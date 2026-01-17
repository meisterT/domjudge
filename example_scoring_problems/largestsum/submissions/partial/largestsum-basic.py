#!/usr/bin/env python3
# O(n^3) brute force - only works for basic (small inputs)
# Times out on efficiency tests, may also fail robustness due to performance
# @EXPECTED_SCORE@: 20

def brute_force(arr):
    """Find maximum sum using O(n^3) brute force"""
    n = len(arr)
    max_sum = arr[0]
    for i in range(n):
        for j in range(i, n):
            curr_sum = 0
            for k in range(i, j + 1):
                curr_sum += arr[k]
            max_sum = max(max_sum, curr_sum)
    return max_sum

n = int(input())
arr = list(map(int, input().split()))
print(brute_force(arr))
