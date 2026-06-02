#!/bin/bash
# Run full inventory import (no stock - needs supplier UUIDs)
cd "$(dirname "$0")/.."
python scripts/kaman_inventory_import.py \
  --execute \
  --base-url https://thex.kaman.dev/api/manager \
  --email thex@kaman.rest \
  --password 1234 \
  --skip-stock \
  --skip-links \
  2>&1 | tee storage/logs/kaman-import-$(date +%Y%m%d-%H%M%S).log
