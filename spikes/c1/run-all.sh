#!/usr/bin/env bash
# Runs every spike on every supported PHP minor and stores the logs under out/.
#
#   ./run-all.sh            # php8.4 and php8.5
#   ./run-all.sh php8.4     # one binary
set -u

cd "$(dirname "$0")" || exit 1
mkdir -p out

BINS=("$@")
if [ ${#BINS[@]} -eq 0 ]; then
    BINS=(php8.4 php8.5)
fi

SPIKES=(
    S12_cross_process_mutation.php
    S13_shared_ardata.php
    S14_attach_side_effects.php
    S16_string_swap.php
    S17_closures_across_fork.php
    S08_S15_mutex_and_bump.php
)

for bin in "${BINS[@]}"; do
    ver=$("$bin" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
    for spike in "${SPIKES[@]}"; do
        log="out/${spike%.php}-${ver}.log"
        printf '=== %s on %s -> %s\n' "$spike" "$bin" "$log"
        timeout 900 "$bin" -d ffi.enable=1 -d opcache.jit=off "$spike" > "$log" 2>&1
        printf '    exit %d, %d OK / %d FAIL\n' "$?" \
            "$(grep -c '^\[ OK \]' "$log")" "$(grep -c '^\[FAIL\]' "$log")"
    done
done
