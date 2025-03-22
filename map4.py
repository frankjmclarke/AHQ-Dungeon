import random

class SpatialGrid:
    def __init__(self, cell_size=50):
        self.cell_size = cell_size
        self.grid = {}

    def _get_keys(self, rect):
        x, y, w, h = rect
        keys = set()
        x1, y1 = int(x // self.cell_size), int(y // self.cell_size)
        x2, y2 = int((x + w) // self.cell_size), int((y + h) // self.cell_size)
        for i in range(x1, x2 + 1):
            for j in range(y1, y2 + 1):
                keys.add((i, j))
        return keys

    def add(self, rect):
        for key in self._get_keys(rect):
            self.grid.setdefault(key, []).append(rect)

    def check_no_overlap(self, rect):
        for key in self._get_keys(rect):
            for r in self.grid.get(key, []):
                if rects_overlap(r, rect):
                    return False
        return True

def rects_overlap(r1, r2):
    x1, y1, w1, h1 = r1
    x2, y2, w2, h2 = r2
    return not (x1 + w1 <= x2 or x2 + w2 <= x1 or y1 + h1 <= y2 or y2 + h2 <= y1)

def place_child(parent_rect, child_size, allowed_segments):
    px, py, pw, ph = parent_rect
    cw, ch = child_size
    order = random.choice(["HV", "VH"])
    if order == "HV":
        h_dir = random.choice(["R", "L"])
        v_dir = random.choice(["D", "U"])
        h_len, h_thick = random.choice(allowed_segments)
        v_len, v_thick = random.choice(allowed_segments)
        if h_dir == "R":
            parent_attach = (px + pw, py + ph/2)
            h_seg_rect = (px + pw, parent_attach[1] - h_thick/2, h_len, h_thick)
            intermediate = (px + pw + h_len, parent_attach[1])
        else:
            parent_attach = (px, py + ph/2)
            h_seg_rect = (px - h_len, parent_attach[1] - h_thick/2, h_len, h_thick)
            intermediate = (px - h_len, parent_attach[1])
        if v_dir == "D":
            v_seg_rect = (intermediate[0] - v_thick/2, intermediate[1], v_thick, v_len)
            child_x = intermediate[0] - cw/2
            child_y = intermediate[1] + v_len
        else:
            v_seg_rect = (intermediate[0] - v_thick/2, intermediate[1] - v_len, v_thick, v_len)
            child_x = intermediate[0] - cw/2
            child_y = intermediate[1] - v_len - ch
        child_rect = (child_x, child_y, cw, ch)
        return child_rect, [h_seg_rect, v_seg_rect]
    else:
        v_dir = random.choice(["D", "U"])
        h_dir = random.choice(["R", "L"])
        v_len, v_thick = random.choice(allowed_segments)
        h_len, h_thick = random.choice(allowed_segments)
        if v_dir == "D":
            parent_attach = (px + pw/2, py + ph)
            v_seg_rect = (parent_attach[0] - v_thick/2, py + ph, v_thick, v_len)
            intermediate = (parent_attach[0], py + ph + v_len)
        else:
            parent_attach = (px + pw/2, py)
            v_seg_rect = (parent_attach[0] - v_thick/2, py - v_len, v_thick, v_len)
            intermediate = (parent_attach[0], py - v_len)
        if h_dir == "R":
            h_seg_rect = (intermediate[0], intermediate[1] - h_thick/2, h_len, h_thick)
            child_x = intermediate[0] + h_len
            child_y = intermediate[1] - ch/2
        else:
            h_seg_rect = (intermediate[0] - h_len, intermediate[1] - h_thick/2, h_len, h_thick)
            child_x = intermediate[0] - h_len - cw
            child_y = intermediate[1] - ch/2
        child_rect = (child_x, child_y, cw, ch)
        return child_rect, [v_seg_rect, h_seg_rect]

def generate_tree_layout(num_nodes, node_sizes, segments):
    nodes = {}
    edges = []
    grid = SpatialGrid()
    root_size = random.choice(node_sizes)
    root_pos = (400, 400)
    root_rect = (*root_pos, *root_size)
    nodes[0] = {'rect': root_rect, 'parent': None}
    grid.add(root_rect)

    for i in range(1, num_nodes):
        parent = random.randint(0, i - 1)
        parent_rect = nodes[parent]['rect']
        child_size = random.choice(node_sizes)

        success = False
        for _ in range(10):  # Retry strategy for multiple placements
            child_rect, segs = place_child(parent_rect, child_size, segments)
            if grid.check_no_overlap(child_rect) and all(grid.check_no_overlap(s) for s in segs):
                success = True
                break

        if not success:
            return None, None

        nodes[i] = {'rect': child_rect, 'parent': parent}
        grid.add(child_rect)
        for seg in segs:
            grid.add(seg)
        edges.append((parent, i, segs))

    return nodes, edges

def generate_svg(nodes, edges):
    all_rects = [v['rect'] for v in nodes.values()] + [s for _, _, segs in edges for s in segs]
    min_x = min(r[0] for r in all_rects)
    min_y = min(r[1] for r in all_rects)
    max_x = max(r[0] + r[2] for r in all_rects)
    max_y = max(r[1] + r[3] for r in all_rects)
    width = max_x - min_x + 20
    height = max_y - min_y + 20
    svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}">']
    for i, node in nodes.items():
        x, y, w, h = node['rect']
        x -= min_x - 10
        y -= min_y - 10
        svg.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="lightblue" stroke="black" />')
        svg.append(f'<text x="{x + w/2}" y="{y + h/2}" font-size="10" text-anchor="middle" dominant-baseline="middle">{i}</text>')
    for _, _, segs in edges:
        for x, y, w, h in segs:
            x -= min_x - 10
            y -= min_y - 10
            svg.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="black" />')
    svg.append('</svg>')
    return "\n".join(svg)

# Example usage
if __name__ == '__main__':
    node_sizes = [(20, 20), (30, 20), (20, 30), (40, 30), (30, 40)]
    segments = [(30, 10), (40, 10), (50, 10), (150, 10)]
    for attempt in range(100):
        nodes, edges = generate_tree_layout(10, node_sizes, segments)
        if nodes:
            with open("mst_output.svg", "w") as f:
                f.write(generate_svg(nodes, edges))
            print(f"Success on attempt {attempt+1}")
            break
    else:
        print("Failed to generate a valid layout in 100 attempts")