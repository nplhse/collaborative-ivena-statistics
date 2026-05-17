#!/usr/bin/env bash

while true; do
  php -d memory_limit=512M bin/console app:legacy-migration:migrate --batch-size=250 --resume
  code=$?

  if [ "$code" -ne 0 ]; then
    echo "Import failed with code $code"
    sleep 10
  else
    echo "Import run finished successfully"
    sleep 1
  fi
done
