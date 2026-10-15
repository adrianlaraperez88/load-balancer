#!/usr/bin/env python3
"""
Comprehensive 10-Backend Scale Benchmark & Timeout Validation Suite.
Measures throughput, latency percentiles, node distribution, and timeout behavior.
Generates matplotlib charts and detailed JSON summaries.
"""

import time
import json
import urllib.request
import urllib.error
import concurrent.futures
from collections import Counter
import statistics
import os
import shutil
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

GATEWAY_URL = "http://localhost:8080/"
TIMEOUT_TEST_URL = "http://localhost:8080/api/workload"
ARTIFACT_DIR = r"C:\Users\Adrian Lara\.gemini\antigravity-ide\brain\5063d4e5-9472-4170-840c-51301face906"

# ==============================================================================
# 1. Timeout Validation Suite
# ==============================================================================
def test_timeout_point(delay_label: str, query_param: str, expected_status: int):
    url = f"{TIMEOUT_TEST_URL}?{query_param}"
    start = time.perf_counter()
    req = urllib.request.Request(
        url,
        headers={"User-Agent": "TimeoutValidator/1.0", "Accept": "application/json"}
    )
    status = 0
    error_msg = ""
    try:
        # Give urllib timeout of 45s so gateway's 10s timeout triggers first after retries
        with urllib.request.urlopen(req, timeout=45) as resp:
            status = resp.status
    except urllib.error.HTTPError as e:
        status = e.code
        error_msg = str(e)
    except Exception as e:
        status = 0
        error_msg = str(e)
    
    elapsed = time.perf_counter() - start
    valid_expected = expected_status if isinstance(expected_status, list) else [expected_status]
    matched = (status in valid_expected)
    print(f"  [Probe] Delay: {delay_label:<10} | Elapsed: {elapsed:6.2f}s | Status: {status} (Expected: {valid_expected}) | Match: {'PASS' if matched else 'FAIL'}")
    return {
        "delay_label": delay_label,
        "query_param": query_param,
        "elapsed_sec": round(elapsed, 2),
        "status": status,
        "expected_status": expected_status,
        "pass": matched,
        "error": error_msg
    }

def run_timeout_suite():
    print("\n" + "=" * 70)
    print(" 1. TIMEOUT VALIDATION SUITE (Gateway Timeout = 10s)")
    print("=" * 70)
    
    test_cases = [
        ("1 ms",   "delay_ms=1",   200),
        ("50 ms",  "delay_ms=50",  200),
        ("250 ms", "delay_ms=250", 200),
        ("1 sec",  "delay_s=1",    200),
        ("3 sec",  "delay_s=3",    200),
        ("6 sec",  "delay_s=6",    200),
        ("8 sec",  "delay_s=8",    200),
        ("12 sec", "delay_s=12",   [502, 504]), # Gateway Timeout threshold (fails after retries)
    ]
    
    results = []
    for label, param, expected in test_cases:
        res = test_timeout_point(label, param, expected)
        results.append(res)
        time.sleep(0.5)
        
    return results

# ==============================================================================
# 2. Concurrency Benchmark Suite across 10 Backend Nodes
# ==============================================================================
def make_request(idx: int):
    start = time.perf_counter()
    req = urllib.request.Request(
        GATEWAY_URL,
        headers={"User-Agent": "ScaleBench-10Node/1.0", "Accept": "application/json"}
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            elapsed_ms = (time.perf_counter() - start) * 1000.0
            data = resp.read()
            body = json.loads(data.decode("utf-8"))
            server = body.get("server", "unknown")
            return {
                "success": True,
                "status": resp.status,
                "server": server,
                "latency_ms": elapsed_ms,
            }
    except Exception as e:
        elapsed_ms = (time.perf_counter() - start) * 1000.0
        return {
            "success": False,
            "status": getattr(e, "code", 0),
            "server": "error",
            "latency_ms": elapsed_ms,
            "error": str(e),
        }

def run_concurrency_tier(concurrency: int, total_requests: int):
    print("\n" + "=" * 70)
    print(f" Concurrency Tier: {concurrency} workers | Total: {total_requests} requests")
    print("=" * 70)

    start_wall = time.perf_counter()
    results = []

    with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as executor:
        futures = [executor.submit(make_request, i) for i in range(total_requests)]
        for f in concurrent.futures.as_completed(futures):
            results.append(f.result())

    total_wall = time.perf_counter() - start_wall
    rps = total_requests / total_wall if total_wall > 0 else 0

    successes = [r for r in results if r["success"]]
    failures = [r for r in results if not r["success"]]
    latencies = [r["latency_ms"] for r in successes]

    server_counts = Counter(r["server"] for r in successes)

    p50 = statistics.median(latencies) if latencies else 0
    latencies_sorted = sorted(latencies)
    p95_idx = int(len(latencies_sorted) * 0.95)
    p95 = latencies_sorted[p95_idx] if latencies_sorted else 0
    p99_idx = int(len(latencies_sorted) * 0.99)
    p99 = latencies_sorted[p99_idx] if latencies_sorted else 0
    min_lat = min(latencies) if latencies else 0
    max_lat = max(latencies) if latencies else 0
    avg_lat = statistics.mean(latencies) if latencies else 0

    print(f"Elapsed Time:       {total_wall:.2f} s")
    print(f"Throughput:         {rps:.1f} Requests / Second")
    print(f"Successful:         {len(successes)} / {total_requests} ({(len(successes)/total_requests)*100:.1f}%)")
    print(f"Failed:             {len(failures)}")
    print(f"Latency Min:        {min_lat:.1f} ms | Avg: {avg_lat:.1f} ms | p50: {p50:.1f} ms | p95: {p95:.1f} ms | p99: {p99:.1f} ms")
    print("\nNode Traffic Spread (10 Backends):")
    for s_name, count in sorted(server_counts.items()):
        pct = (count / len(successes)) * 100.0 if successes else 0
        bar = "#" * int(pct / 2)
        print(f"  {s_name:<15} : {count:>5} req ({pct:>5.1f}%) {bar}")

    return {
        "concurrency": concurrency,
        "total_requests": total_requests,
        "elapsed_sec": round(total_wall, 2),
        "rps": round(rps, 1),
        "success_rate_pct": round((len(successes)/total_requests)*100, 1),
        "p50_ms": round(p50, 1),
        "p95_ms": round(p95, 1),
        "p99_ms": round(p99, 1),
        "min_ms": round(min_lat, 1),
        "max_ms": round(max_lat, 1),
        "server_distribution": dict(server_counts),
    }

# ==============================================================================
# 3. Chart Generation with Matplotlib
# ==============================================================================
def generate_charts(benchmark_summary, timeout_results, output_png):
    plt.style.use('dark_background')
    fig = plt.figure(figsize=(16, 12), dpi=150)
    fig.patch.set_facecolor('#0f172a')

    # Color palette
    cyan = '#38bdf8'
    emerald = '#34d399'
    amber = '#f59e0b'
    rose = '#f43f5e'
    indigo = '#818cf8'

    # --- Subplot 1: Throughput (RPS) vs Concurrency ---
    ax1 = fig.add_subplot(2, 2, 1)
    ax1.set_facecolor('#1e293b')
    concurrencies = [b['concurrency'] for b in benchmark_summary]
    rps_values = [b['rps'] for b in benchmark_summary]
    ax1.plot(concurrencies, rps_values, marker='o', linewidth=2.5, markersize=8, color=cyan, label='Throughput (RPS)')
    for c, r in zip(concurrencies, rps_values):
        ax1.annotate(f"{r:.1f} RPS", (c, r), textcoords="offset points", xytext=(0, 10), ha='center', color='#ffffff', fontweight='bold')
    ax1.set_title("Throughput (RPS) vs Concurrency (10 Backends)", fontsize=13, fontweight='bold', color='#f8fafc', pad=12)
    ax1.set_xlabel("Concurrent Client Workers", color='#cbd5e1')
    ax1.set_ylabel("Requests Per Second", color='#cbd5e1')
    ax1.grid(True, linestyle='--', alpha=0.3, color='#475569')
    ax1.set_ylim(bottom=0, top=max(rps_values) * 1.25)

    # --- Subplot 2: Latency Percentiles Curve ---
    ax2 = fig.add_subplot(2, 2, 2)
    ax2.set_facecolor('#1e293b')
    p50s = [b['p50_ms'] for b in benchmark_summary]
    p95s = [b['p95_ms'] for b in benchmark_summary]
    p99s = [b['p99_ms'] for b in benchmark_summary]
    ax2.plot(concurrencies, p50s, marker='s', linewidth=2, color=emerald, label='Median (p50)')
    ax2.plot(concurrencies, p95s, marker='^', linewidth=2, color=amber, label='95th % (p95)')
    ax2.plot(concurrencies, p99s, marker='D', linewidth=2, color=rose, label='99th % (p99)')
    ax2.set_title("Response Time Percentiles Curve (ms)", fontsize=13, fontweight='bold', color='#f8fafc', pad=12)
    ax2.set_xlabel("Concurrent Client Workers", color='#cbd5e1')
    ax2.set_ylabel("Latency (milliseconds)", color='#cbd5e1')
    ax2.grid(True, linestyle='--', alpha=0.3, color='#475569')
    ax2.legend(loc='upper left', framealpha=0.8, facecolor='#0f172a', edgecolor='#475569')

    # --- Subplot 3: Traffic Distribution Across 10 Backends ---
    ax3 = fig.add_subplot(2, 2, 3)
    ax3.set_facecolor('#1e293b')
    # Use distribution from the largest test tier
    last_tier = benchmark_summary[-1]
    dist = last_tier['server_distribution']
    servers = sorted(dist.keys())
    counts = [dist[s] for s in servers]
    total_reqs = sum(counts)
    percentages = [(c / total_reqs) * 100.0 for c in counts]
    
    bars = ax3.bar(servers, percentages, color=indigo, edgecolor='#38bdf8', width=0.6, alpha=0.9)
    ax3.axhline(10.0, color='#ef4444', linestyle='--', linewidth=1.5, label='Optimal 10.0% Line')
    for bar, pct in zip(bars, percentages):
        height = bar.get_height()
        ax3.annotate(f"{pct:.1f}%",
                    xy=(bar.get_x() + bar.get_width() / 2, height),
                    xytext=(0, 4), textcoords="offset points",
                    ha='center', va='bottom', color='#ffffff', fontsize=9, fontweight='bold')
    ax3.set_title(f"Node Load Distribution Uniformity ({last_tier['total_requests']:,} Requests)", fontsize=13, fontweight='bold', color='#f8fafc', pad=12)
    ax3.set_xticks(range(len(servers)))
    ax3.set_xticklabels(servers, rotation=35, ha='right', fontsize=9)
    ax3.set_ylim(0, max(percentages) * 1.3)
    ax3.grid(True, axis='y', linestyle='--', alpha=0.3, color='#475569')
    ax3.legend(loc='upper right', framealpha=0.8, facecolor='#0f172a')

    # --- Subplot 4: Timeout Threshold Verification Curve ---
    ax4 = fig.add_subplot(2, 2, 4)
    ax4.set_facecolor('#1e293b')
    probe_labels = [t['delay_label'] for t in timeout_results]
    elapsed_times = [t['elapsed_sec'] for t in timeout_results]
    statuses = [t['status'] for t in timeout_results]
    colors = [emerald if s == 200 else rose for s in statuses]

    bars2 = ax4.bar(probe_labels, elapsed_times, color=colors, edgecolor='#ffffff', width=0.55, alpha=0.85)
    ax4.axhline(10.0, color='#f59e0b', linestyle='--', linewidth=2, label='Gateway Timeout Limit (10.0s)')
    
    for bar, st in zip(bars2, statuses):
        height = bar.get_height()
        status_txt = f"{st} OK" if st == 200 else f"{st} TIMEOUT"
        ax4.annotate(status_txt,
                    xy=(bar.get_x() + bar.get_width() / 2, height),
                    xytext=(0, 5), textcoords="offset points",
                    ha='center', va='bottom', color='#f8fafc', fontsize=8, fontweight='bold')
                    
    ax4.set_title("Timeout Threshold Validation (1ms to 15s)", fontsize=13, fontweight='bold', color='#f8fafc', pad=12)
    ax4.set_xlabel("Injected Artificial Backend Delay", color='#cbd5e1')
    ax4.set_ylabel("Elapsed Time to Response (seconds)", color='#cbd5e1')
    ax4.grid(True, axis='y', linestyle='--', alpha=0.3, color='#475569')
    ax4.legend(loc='upper left', framealpha=0.8, facecolor='#0f172a')

    plt.suptitle("ISG Enterprise Load Balancer - 10-Backend Scale & Latency Report", fontsize=17, fontweight='bold', color='#38bdf8', y=0.98)
    plt.tight_layout(rect=[0, 0.03, 1, 0.95])
    
    plt.savefig(output_png, facecolor=fig.get_facecolor(), edgecolor='none')
    print(f"\n[Visual Graphic] Chart saved to {output_png}")
    
    # Also copy to artifact directory
    if os.path.exists(ARTIFACT_DIR):
        artifact_chart = os.path.join(ARTIFACT_DIR, "performance_report_10nodes.png")
        shutil.copy2(output_png, artifact_chart)
        print(f"[Visual Graphic] Copied to artifact directory: {artifact_chart}")

if __name__ == "__main__":
    # 1. Run timeout suite
    timeout_results = run_timeout_suite()
    
    # 2. Run multi-tier concurrency benchmark
    tiers = [
        (20, 500),    # Warmup
        (50, 1500),   # Moderate load
        (100, 3000),  # High concurrency
        (200, 5000),  # Heavy saturation
    ]
    
    summary = []
    for conc, total in tiers:
        res = run_concurrency_tier(conc, total)
        summary.append(res)
        time.sleep(1)

    print("\n\n" + "=" * 80)
    print(" 10-BACKEND CLUSTER BENCHMARK SUMMARY")
    print("=" * 80)
    print(f"{'Concurrency':<12} | {'Total Req':<10} | {'Elapsed':<10} | {'Throughput':<12} | {'p50 (ms)':<10} | {'p95 (ms)':<10} | {'Success':<8}")
    print("-" * 80)
    for s in summary:
        print(f"{s['concurrency']:<12} | {s['total_requests']:<10} | {s['elapsed_sec']:<8}s | {s['rps']:<8} RPS | {s['p50_ms']:<10} | {s['p95_ms']:<10} | {s['success_rate_pct']}%")

    # 100k Projection calculation
    max_rps = max(s['rps'] for s in summary)
    ten_min_reqs = int(max_rps * 600)
    print("\n" + "=" * 80)
    print(f" CAPACITY PROJECTION: At sustained {max_rps:.1f} RPS:")
    print(f" - In 10 Minutes (600s): {ten_min_reqs:,} Requests Handled")
    print(f" - 100,000 requests completion time: {100000 / max_rps:.1f} seconds ({(100000 / max_rps)/60:.1f} minutes)")
    print("=" * 80)

    # Save JSON data
    output_json = {
        "timeout_validation": timeout_results,
        "concurrency_tiers": summary,
        "projection_100k": {
            "max_rps": max_rps,
            "projected_10min_capacity": ten_min_reqs,
            "time_for_100k_minutes": round((100000 / max_rps)/60, 2)
        }
    }
    with open("benchmark_10nodes_results.json", "w") as f:
        json.dump(output_json, f, indent=2)

    # 3. Generate high-resolution graphic chart
    chart_file = os.path.join("docker", "performance_report_10nodes.png")
    generate_charts(summary, timeout_results, chart_file)
