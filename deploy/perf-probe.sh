#!/bin/bash
base="https://hinyerevan.ru/api"
urls=(
  "photos/11180?lang=ru&translate=main"
  "photos/11180?lang=hy"
  "users/b3da541e9442c40f9579b36083fe51d0?lang=ru"
  "users/b3da541e9442c40f9579b36083fe51d0?lang=hy"
  "ratings?lang=ru"
  "photos?lang=ru&per_page=12"
  "photos/random?lang=ru"
  "news?lang=ru"
)
for u in "${urls[@]}"; do
  printf '%-55s ' "$u"
  curl -s -o /dev/null -w 'total=%{time_total}s\n' "$base/$u"
done
