#!/usr/bin/env python3
"""
High-concurrency benchmark tool for ISG Load Balancer.
Tests gateway throughput, latency distributions, and node traffic spread.
"""

import time
import json
import concurrent.futures
import urllib.request
import urllib.error
from collections import Counter
import statistics

GATEWAY_URL = "http://localhost:8080/"

def make_request(request_id: int):
    start = time.perf_counter()
    req = urllib.request.Request(
        GATEWAY_URL,
        headers={
            "User-Agent": "LoadBalancer-Benchmark/1.0",
            "Accept": "application/json",
        }
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
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

def run_benchmark_tier(concurrency: int, total_requests: int):
    print(f"\n=================================================================")
    print(f" Running Benchmark: Concurrency = {concurrency}, Total = {total_requests}")
    print(f"=================================================================")

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
    print(f"Latency Min:        {min_lat:.1f} ms")
    print(f"Latency Avg:        {avg_lat:.1f} ms")
    print(f"Latency p50:        {p50:.1f} ms")
    print(f"Latency p95:        {p95:.1f} ms")
    print(f"Latency p99:        {p99:.1f} ms")
    print(f"Latency Max:        {max_lat:.1f} ms")
    print("\nNode Traffic Distribution:")
    for server, count in sorted(server_counts.items()):
        pct = (count / len(successes)) * 100.0 if successes else 0
        bar = "#" * int(pct / 4)
        print(f"  {server:<15} : {count:>5} req ({pct:>5.1f}%) {bar}")

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

if __name__ == "__main__":
    tiers = [
        (10, 200),   # Warmup
        (50, 1000),  # Moderate scale
        (100, 1500), # High concurrency
        (200, 2000), # Peak concurrency
    ]

    summary = []
    for conc, total in tiers:
        res = run_benchmark_tier(conc, total)
        summary.append(res)
        time.sleep(1)

    print("\n\n=================================================================")
    print(" SUMMARY MATRIX (1 Gateway + 5 Backend Servers)")
    print("=================================================================")
    print(f"{'Concurrency':<12} | {'Total Req':<10} | {'RPS':<10} | {'p50 (ms)':<10} | {'p95 (ms)':<10} | {'Success Rate':<12}")
    print("-" * 75)
    for s in summary:
        print(f"{s['concurrency']:<12} | {s['total_requests']:<10} | {s['rps']:<10} | {s['p50_ms']:<10} | {s['p95_ms']:<10} | {s['success_rate_pct']}%")

    with open("benchmark_results.json", "w") as f:
        json.dump(summary, f, indent=2)
    print("\nResults saved to benchmark_results.json")
