#!/usr/bin/env python3
# O(n^2) solution - handles basic and efficiency/small, but TLE on medium/large
# Also handles robustness (edge cases)
# basic (20) + efficiency avg of (small=15, medium=0, large=0) = 20 + 5 = 25
# But robustness uses MIN, so we need to pass all robustness cases to get points there
# With correct edge case handling: basic (20) + small (5 from avg) + robustness (50) = 75
# Actually: efficiency is AVG(15, 0, 0) = 5, so 20 + 5 + 50 = 75
# @EXPECTED_SCORE@: 75

def quadratic(arr):
    """Find maximum sum using O(n^2)"""
    n = len(arr)
    max_sum = arr[0]
    for i in range(n):
        curr_sum = 0
        for j in range(i, n):
            curr_sum += arr[j]
            max_sum = max(max_sum, curr_sum)
    return max_sum

n = int(input())
arr = list(map(int, input().split()))
print(quadratic(arr))
