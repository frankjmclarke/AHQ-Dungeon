import random
import math

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

def try_place_all(parent_rect, child_size, allowed_segments, grid):
    best = None
    min_cost = float('inf')
    for order in ["HV", "VH"]:
        for h_dir in ["R", "L"]:
            for v_dir in ["D", "U"]:
                for h_len, h_thick in allowed_segments:
                    for v_len, v_thick in allowed_segments:
                        if order == "HV":
                            if h_dir == "R":
                                parent_attach = (parent_rect[0] + parent_rect[2], parent_rect[1] + parent_rect[3]/2)
                                h_seg_rect = (parent_attach[0], parent_attach[1] - h_thick/2, h_len, h_thick)
                                intermediate = (parent_attach[0] + h_len, parent_attach[1])
                            else:
                                parent_attach = (parent_rect[0], parent_rect[1] + parent_rect[3]/2)
                                h_seg_rect = (parent_attach[0] - h_len, parent_attach[1] - h_thick/2, h_len, h_thick)
                                intermediate = (parent_attach[0] - h_len, parent_attach[1])
                            if v_dir == "D":
                                v_seg_rect = (intermediate[0] - v_thick/2, intermediate[1], v_thick, v_len)
                                child_x = intermediate[0] - child_size[0]/2
                                child_y = intermediate[1] + v_len
                            else:
                                v_seg_rect = (intermediate[0] - v_thick/2, intermediate[1] - v_len, v_thick, v_len)
                                child_x = intermediate[0] - child_size[0]/2
                                child_y = intermediate[1] - v_len - child_size[1]
                            child_rect = (child_x, child_y, *child_size)
                            segs = [h_seg_rect, v_seg_rect]
                        else:
                            if v_dir == "D":
                                parent_attach = (parent_rect[0] + parent_rect[2]/2, parent_rect[1] + parent_rect[3])
                                v_seg_rect = (parent_attach[0] - v_thick/2, parent_rect[1] + parent_rect[3], v_thick, v_len)
                                intermediate = (parent_attach[0], parent_rect[1] + parent_rect[3] + v_len)
                            else:
                                parent_attach = (parent_rect[0] + parent_rect[2]/2, parent_rect[1])
                                v_seg_rect = (parent_attach[0] - v_thick/2, parent_rect[1] - v_len, v_thick, v_len)
                                intermediate = (parent_attach[0], parent_rect[1] - v_len)
                            if h_dir == "R":
                                h_seg_rect = (intermediate[0], intermediate[1] - h_thick/2, h_len, h_thick)
                                child_x = intermediate[0] + h_len
                                child_y = intermediate[1] - child_size[1]/2
                            else:
                                h_seg_rect = (intermediate[0] - h_len, intermediate[1] - h_thick/2, h_len, h_thick)
                                child_x = intermediate[0] - h_len - child_size[0]
                                child_y = intermediate[1] - child_size[1]/2
                            child_rect = (child_x, child_y, *child_size)
                            segs = [v_seg_rect, h_seg_rect]

                        if grid.check_no_overlap(child_rect) and all(grid.check_no_overlap(s) for s in segs):
                            cost = h_len + v_len
                            if cost < min_cost:
                                best = (child_rect, segs)
                                min_cost = cost
    return best

def generate_tree_layout(num_nodes, node_sizes, segments):
    nodes = {}
    edges = []
    grid = SpatialGrid()
    root_size = random.choice(node_sizes)
    center = (500, 500)
    root_rect = (*center, *root_size)
    nodes[0] = {'rect': root_rect, 'parent': None}
    grid.add(root_rect)
    depths = {0: 0}
    angle_index = 0
    golden_angle = math.radians(137.5)
    spacing = 150

    for i in range(1, num_nodes):
        parent = random.randint(0, i - 1)
        depth = depths[parent] + 1
        angle = angle_index * golden_angle
        radius = spacing * depth
        offset_x = math.cos(angle) * radius
        offset_y = math.sin(angle) * radius
        parent_rect = nodes[parent]['rect']
        shifted_parent = (parent_rect[0] + offset_x, parent_rect[1] + offset_y, parent_rect[2], parent_rect[3])
        child_size = random.choice(node_sizes)

        result = try_place_all(shifted_parent, child_size, segments, grid)
        if not result:
            return None, None
        child_rect, segs = result
        nodes[i] = {'rect': child_rect, 'parent': parent}
        depths[i] = depth
        angle_index += 1
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
        nodes, edges = generate_tree_layout(30, node_sizes, segments)
        if nodes:
            with open("mst_output.svg", "w") as f:
                f.write(generate_svg(nodes, edges))
            print(f"Success on attempt {attempt+1}")
            break
    else:
        print("Failed to generate a valid layout in 100 attempts")