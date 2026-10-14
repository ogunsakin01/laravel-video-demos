# The promo video

The dark-mode promo at the top of the README (`docs/social/laravel-video-demos-promo.mp4`), made from footage of the demo app.

1. **Serve the demo app** with Playwright reachable and its own address set, so the start-page list can open pages:

   ```bash
   composer serve     # http://127.0.0.1:8123
   ```

2. **Film the footage** into `footage/` (dark mode, at 2× pixel density). Each clip comes with the positions of what it shows, which the edit uses to zoom and highlight:

   ```bash
   NODE_PATH=/path/to/node_modules FFMPEG=/path/to/ffmpeg node workbench/promo/capture.cjs          # every scene
   NODE_PATH=/path/to/node_modules node workbench/promo/capture.cjs again remove                    # some of them
   ```

   `spotlight-example.mp4` is a recording made with the Spotlight style; copy one into `footage/`.

3. **Edit** in `remotion/src/Promo.tsx`: each scene's headline, the footage it plays, and where the camera points. Check single frames with:

   ```bash
   cd workbench/promo/remotion && npm install && node render.mjs --stills 300,1200
   ```

4. **Render** it. Frames are rendered one by one with software rendering, checked for glitches and redrawn, then encoded over the promo in `docs/social`:

   ```bash
   FFMPEG=/path/to/ffmpeg bash workbench/promo/build.sh
   ```

Make the README's preview from the first 40 seconds:

```bash
ffmpeg -t 40 -i docs/social/laravel-video-demos-promo.mp4 -filter_complex "[0:v]scale=480:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=96:stats_mode=diff[p];[b][p]paletteuse=dither=none:diff_mode=rectangle" -r 8 docs/promo.gif
```

Solid colours only, no gradients, as everywhere in the package.
