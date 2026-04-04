#!/usr/bin/env bash
# E2E test assertion helpers for RL module.
# Consistent with dxpr_builder and dxpr_theme_helper test patterns.

set -euo pipefail

PASS=0
FAIL=0
TOTAL=0

section() {
  echo ""
  echo "=== $1 ==="
  echo ""
}

assert_eq() {
  local desc="$1" expected="$2" actual="$3"
  TOTAL=$((TOTAL + 1))
  if [ "$expected" = "$actual" ]; then
    echo "  PASS: $desc"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $desc"
    echo "    expected: $expected"
    echo "    actual:   $actual"
    FAIL=$((FAIL + 1))
  fi
}

assert_success() {
  local desc="$1"
  shift
  TOTAL=$((TOTAL + 1))
  if output=$("$@" 2>&1); then
    echo "  PASS: $desc"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $desc (exit code $?)"
    echo "    output: $output"
    FAIL=$((FAIL + 1))
  fi
}

assert_fail() {
  local desc="$1"
  shift
  TOTAL=$((TOTAL + 1))
  if output=$("$@" 2>&1); then
    echo "  FAIL: $desc (expected failure, got success)"
    echo "    output: $output"
    FAIL=$((FAIL + 1))
  else
    echo "  PASS: $desc (correctly failed)"
    PASS=$((PASS + 1))
  fi
}

assert_has() {
  local desc="$1" needle="$2" haystack="$3"
  TOTAL=$((TOTAL + 1))
  if echo "$haystack" | grep -q "$needle"; then
    echo "  PASS: $desc"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $desc"
    echo "    expected to contain: $needle"
    echo "    actual: $(echo "$haystack" | head -5)"
    FAIL=$((FAIL + 1))
  fi
}

assert_not_empty() {
  local desc="$1" value="$2"
  TOTAL=$((TOTAL + 1))
  if [ -n "$value" ]; then
    echo "  PASS: $desc"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $desc (empty)"
    FAIL=$((FAIL + 1))
  fi
}

assert_runs() {
  local desc="$1"
  shift
  assert_success "$desc" "$@"
}

assert_dry_run() {
  local desc="$1" output="$2"
  TOTAL=$((TOTAL + 1))
  if echo "$output" | yq -e '.dry_run == true' > /dev/null 2>&1; then
    echo "  PASS: $desc"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $desc (dry_run not true)"
    echo "    output: $(echo "$output" | head -5)"
    FAIL=$((FAIL + 1))
  fi
}

print_summary() {
  echo ""
  echo "================================"
  echo "  Results: $PASS passed, $FAIL failed, $TOTAL total"
  echo "================================"
  if [ "$FAIL" -gt 0 ]; then
    exit 1
  fi
}
