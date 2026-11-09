import sys, json

def moving_average(series, window=3):
    out = []
    for i in range(len(series)):
        start = max(0, i - window + 1)
        w = series[start:i+1]
        out.append(sum(w) / len(w) if w else 0)
    return out

def slope(series):
    n = len(series)
    if n < 2: 
        return 0.0
    x = list(range(n))
    xm = sum(x)/n
    ym = sum(series)/n
    num = sum((xi-xm)*(yi-ym) for xi, yi in zip(x, series))
    den = sum((xi-xm)**2 for xi in x)
    return num/den if den else 0.0

try:
    data = json.load(sys.stdin)
    counts = data.get('counts', [])
    ma = moving_average(counts, window=3)
    s = slope(counts)
    print(json.dumps({'moving_average': ma, 'trend_slope': s}))
except Exception as e:
    print(json.dumps({'error': str(e)}))
