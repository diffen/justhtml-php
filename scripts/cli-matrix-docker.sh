#!/usr/bin/env bash
set -euo pipefail

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required. Install Docker Desktop and try again." >&2
  exit 1
fi

versions=("$@")
if [ "${#versions[@]}" -eq 0 ]; then
  versions=(7.4 8.0 8.1 8.2 8.3 8.4 8.5)
fi

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixture="examples/fixtures/wikipedia-earth.html"

if [ ! -f "${root_dir}/${fixture}" ]; then
  echo "Missing fixture: ${fixture}" >&2
  exit 1
fi

baseline_version="${versions[0]}"
baseline_dir="$(mktemp -d)"
current_dir=""
cleanup() {
  if [ -n "${current_dir}" ] && [ -d "${current_dir}" ]; then
    rm -rf "${current_dir}"
  fi
  if [ -n "${baseline_dir}" ] && [ -d "${baseline_dir}" ]; then
    rm -rf "${baseline_dir}"
  fi
}
trap cleanup EXIT

fail() {
  echo "FAIL: $1" >&2
  exit 1
}

assert_eq() {
  local expected="$1"
  local actual="$2"
  if [ "$actual" != "$expected" ]; then
    fail "Expected '${expected}', got '${actual}'"
  fi
}

record_output() {
  local name="$1"
  local value="$2"
  local diff_output=""
  if [ "$v" = "$baseline_version" ]; then
    printf '%s' "$value" > "${baseline_dir}/${name}"
    return
  fi
  printf '%s' "$value" > "${current_dir}/${name}"
  diff_output="$(diff -u "${baseline_dir}/${name}" "${current_dir}/${name}" || true)"
  if [ -n "${diff_output}" ]; then
    echo "${diff_output}" >&2
    fail "Output mismatch for ${name} between PHP ${baseline_version} and PHP ${v}"
  fi
}

for v in "${versions[@]}"; do
  echo "== PHP ${v} =="
  current_dir="$(mktemp -d)"

  run_php() {
    docker run --rm -v "${root_dir}":/work -w /work "php:${v}-cli" php "$@"
  }

  run_sh() {
    docker run --rm -v "${root_dir}":/work -w /work "php:${v}-cli" sh -lc "$1"
  }

  # --count
  out="$(run_php bin/justhtml "${fixture}" --selector "h1#firstHeading" --count)"
  assert_eq "1" "$out"
  record_output "count" "$out"

  # --inner / --outer
  out="$(run_php bin/justhtml "${fixture}" --selector "h1#firstHeading" --format html --outer)"
  echo "$out" | grep -q "<h1" || fail "--outer did not include <h1"
  record_output "outer" "$out"
  out="$(run_php bin/justhtml "${fixture}" --selector "h1#firstHeading" --format html --inner)"
  echo "$out" | grep -q "<h1" && fail "--inner unexpectedly included <h1"
  echo "$out" | grep -q "mw-page-title-main" || fail "--inner missing title span"
  record_output "inner" "$out"

  # --limit / --first
  out="$(run_sh "php bin/justhtml ${fixture} --selector 'p' --format text --limit 1 | wc -l | tr -d ' '")"
  assert_eq "1" "$out"
  record_output "limit1_lines" "$out"
  out="$(run_sh "php bin/justhtml ${fixture} --selector 'p' --format text --first | wc -l | tr -d ' '")"
  assert_eq "1" "$out"
  record_output "first_lines" "$out"

  # --format text output consistency
  out="$(run_php bin/justhtml "${fixture}" --selector "title" --format text --first)"
  assert_eq "Earth - Wikipedia" "$out"
  record_output "text_title" "$out"

  # --attr / --missing / --separator
  out="$(run_php bin/justhtml "${fixture}" --selector "a.mw-jump-link" --attr href --first)"
  assert_eq "#bodyContent" "$out"
  record_output "attr_href" "$out"
  out="$(run_php bin/justhtml "${fixture}" --selector "a.mw-jump-link" --attr href --attr class --first)"
  assert_eq "#bodyContent	mw-jump-link" "$out"
  record_output "attr_href_class" "$out"
  out="$(run_php bin/justhtml "${fixture}" --selector "a.mw-jump-link" --attr href --attr rel --first)"
  assert_eq "#bodyContent	__MISSING__" "$out"
  record_output "attr_href_rel_missing" "$out"
  out="$(run_php bin/justhtml "${fixture}" --selector "a.mw-jump-link" --attr href --attr rel --missing NA --first)"
  assert_eq "#bodyContent	NA" "$out"
  record_output "attr_href_rel_missing_override" "$out"
  out="$(run_php bin/justhtml "${fixture}" --selector "a.mw-jump-link" --attr href --attr rel --separator "," --first)"
  assert_eq "#bodyContent,__MISSING__" "$out"
  record_output "attr_separator" "$out"

  # Conflict checks
  if run_sh "php bin/justhtml ${fixture} --selector 'p' --count --format text" >/dev/null 2>&1; then
    fail "--count should fail with --format"
  fi
  if run_sh "php bin/justhtml ${fixture} --selector 'a' --attr href --format text" >/dev/null 2>&1; then
    fail "--attr should fail with --format"
  fi
  if run_sh "php bin/justhtml ${fixture} --selector 'p' --first --limit 2" >/dev/null 2>&1; then
    fail "--first should fail with --limit"
  fi

  # --limit validation messages
  set +e
  out="$(run_sh "php bin/justhtml ${fixture} --selector 'p' --limit 0" 2>&1)"
  status=$?
  set -e
  if [ "$status" -eq 0 ]; then
    fail "--limit 0 should fail"
  fi
  echo "$out" | grep -q "Invalid value for --limit" || fail "Missing invalid --limit error"

  set +e
  out="$(run_sh "php bin/justhtml ${fixture} --selector 'p' --limit 999999999999999999999999" 2>&1)"
  status=$?
  set -e
  if [ "$status" -eq 0 ]; then
    fail "--limit overflow should fail"
  fi
  echo "$out" | grep -q "Value for --limit is too large" || fail "Missing overflow --limit error"

  rm -rf "${current_dir}"
  current_dir=""
  echo ""
done
