import sys, os, zlib, struct
def read(p):
    b = open(p, 'rb').read()
    w, h = struct.unpack('>II', b[16:24])
    i, raw = 8, b''
    while i < len(b):
        n = struct.unpack('>I', b[i:i + 4])[0]; t = b[i + 4:i + 8]
        if t == b'IDAT': raw += b[i + 8:i + 8 + n]
        i += 12 + n
    d = zlib.decompress(raw); out = []; prev = bytes(w)
    for y in range(h):
        f = d[y * (w + 1)]; row = bytearray(d[y * (w + 1) + 1:(y + 1) * (w + 1)])
        for x in range(w):
            a = row[x - 1] if x else 0; up = prev[x]; c = prev[x - 1] if x else 0
            if f == 1: row[x] = (row[x] + a) & 255
            elif f == 2: row[x] = (row[x] + up) & 255
            elif f == 3: row[x] = (row[x] + (a + up) // 2) & 255
            elif f == 4:
                pa, pb, pc = abs(up - c), abs(a - c), abs(a + up - 2 * c)
                pr = a if pa <= pb and pa <= pc else (up if pb <= pc else c)
                row[x] = (row[x] + pr) & 255
        out.append(bytes(row)); prev = row
    return b''.join(out)
d = sys.argv[1]
fs = sorted(os.listdir(d)); frames = [read(os.path.join(d, f)) for f in fs]
N = len(frames[0])
diff = lambda a, b: sum(abs(x - y) for x, y in zip(a, b)) / N
bad = []
# A glitch is a run of 1-8 frames that differs from the frames on both sides,
# while those two are close to each other.
i = 1
while i < len(frames) - 1:
    found = False
    for k in range(1, 9):
        if i + k >= len(frames): break
        before, after = frames[i - 1], frames[i + k]
        inside = [diff(before, frames[j]) for j in range(i, i + k)]
        outside = diff(before, after)
        if min(inside) > 4 and outside < min(inside) / 2 and diff(frames[i + k - 1], after) > 4:
            bad.append((i, k, round(i / 30, 2), round(min(inside), 1), round(outside, 1)))
            i += k; found = True; break
    if not found: i += 1
if len(sys.argv) > 2 and sys.argv[2] == '--list':
    print(','.join(str(f) for (i, k, *_ ) in bad for f in range(i, i + k)))
else:
    print(len(frames), 'frames;', len(bad), 'glitches (first frame, length, seconds, change, around):')
    for x in bad: print(' ', x)
