#!/usr/bin/env bash
# Turns a recorded clip (.webm) into the files the website uses: an MP4, a GIF and a poster frame.
#   .wordpress-org/clips/make.sh /tmp/clips/combine-coffee.webm site/images [POSTER_SECONDS, default 21]
# The first second is cut: it holds the page loading.
set -euo pipefail
src=$1
out=$2
at=${3:-21}
name=$(basename "$src" .webm)
mkdir -p "$out"
ffmpeg -v error -y -ss 1 -i "$src" -c:v libx264 -preset slow -crf 26 -pix_fmt yuv420p -movflags +faststart -an "$out/$name.mp4"
ffmpeg -v error -y -ss 1 -i "$src" -vf "fps=10,scale=800:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=96:stats_mode=diff[p];[b][p]paletteuse=dither=bayer:bayer_scale=4:diff_mode=rectangle" "$out/$name.gif"
ffmpeg -v error -y -ss "$at" -i "$src" -frames:v 1 -q:v 4 "$out/$name.jpg"
ls -l "$out/$name".*
