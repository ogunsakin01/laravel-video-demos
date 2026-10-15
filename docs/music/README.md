# Built-in music

Four short pieces for the background of videos: **calm**, **upbeat**, **corporate** and **cinematic**. Each loops seamlessly, so it plays under a video of any length.

They were composed for this package by [`workbench/music/compose.cjs`](../../workbench/music/compose.cjs), a small synthesizer with no samples or outside material, so they are the package's own work. They are dedicated to the public domain under [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/): use them in any video, commercial or not, without credit.

The package downloads a track the first time a video uses it; they are not part of the Composer download. To compose them again:

```bash
node workbench/music/compose.cjs /tmp/music
for n in calm upbeat corporate cinematic; do ffmpeg -i /tmp/music/$n.wav -c:a libmp3lame -b:a 128k docs/music/$n.mp3; done
```
