#!/bin/bash
# Renders the promo frame by frame, redraws frames that came out glitched,
# then encodes docs/social/laravel-video-demos-promo.mp4.
#   FFMPEG=/path/to/ffmpeg bash workbench/promo/build.sh
set -e
DIR=$(cd "$(dirname "$0")" && pwd)
FFMPEG=${FFMPEG:-ffmpeg}
F=$DIR/frames; G=$DIR/gray
OUT=${OUT:-$DIR/../../docs/social/laravel-video-demos-promo.mp4}
rm -rf "$F"; cd "$DIR/remotion"
node render.mjs --frames "$F"
scan() { rm -rf "$G"; mkdir -p "$G"; "$FFMPEG" -loglevel error -y -framerate 30 -i "$F/element-%04d.jpeg" -vf "scale=64:64,format=gray" -c:v png "$G/%05d.png"; }
prev=""
for pass in 1 2 3 4 5; do
  scan
  list=$(python3 "$DIR/glitch.py" "$G" --list)
  echo "pass $pass: suspect frames: ${list:-none}"
  [ -z "$list" ] && break
  # Frames that come back the same after a fresh render are real changes, not glitches.
  [ "$list" = "$prev" ] && { echo "the same frames again: real changes, not glitches"; break; }
  prev=$list
  node render.mjs --redo "$F" "$list"
done
"$FFMPEG" -loglevel error -y -framerate 30 -i "$F/element-%04d.jpeg" -c:v libx264 -crf 18 -preset slow -profile:v high -pix_fmt yuv420p -movflags +faststart -an "$OUT"
rm -rf "$F" "$G"
echo "built $OUT"
