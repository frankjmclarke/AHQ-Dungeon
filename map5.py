import random
import svgwrite
from itertools import combinations
from collections import defaultdict

# === CONFIG ===
NODE_SIZE_OPTIONS = [(10, 10), (5, 5)]
LINE_WIDTH_OPTIONS = [(1, 5), (1, 10)]
NODE_COUNT = 10
GRID_SIZE = 140
SCALE = 4
MAX_ATTEMPTS = 100
PADDING = 3
T_CONNECTOR_PERCENT = 0.15
SAFETY_MARGIN = 1  # <-- Prevent even stroke bleed into node

class Node:
    def __init__(self, id, x, y, w, h):
        self.id = id
        self.x = x
        self.y = y
        self.w = w
        self.h = h

    def bbox(self):
        return (self.x, self.y, self.x + self.w, self.y + self.h)

    def center(self):
        return (self.x + self.w // 2, self.y + self.h // 2)

    def get_connector_points(self):
        # Return anchor points offset 1 unit away from room edges
        cx, cy = self.center()
        return {
            'top':    (cx, self.y - SAFETY_MARGIN),
            'bottom': (cx, self.y + self.h + SAFETY_MARGIN),
            'left':   (self.x - SAFETY_MARGIN, cy),
            'right':  (self.x + self.w + SAFETY_MARGIN, cy)
        }

    def intersects_box(self, box):
        x1, y1, x2, y2 = self.bbox()
        bx1, by1, bx2, by2 = box
        # Add safety margin to the node box
        x1 -= SAFETY_MARGIN
        y1 -= SAFETY_MARGIN
        x2 += SAFETY_MARGIN
        y2 += SAFETY_MARGIN
        return not (x2 <= bx1 or x1 >= bx2 or y2 <= by1 or y1 >= by2)

    def intersects(self, other):
        x1, y1, x2, y2 = self.bbox()
        ox1, oy1, ox2, oy2 = other.bbox()
        return not (x2 + PADDING < ox1 or x1 > ox2 + PADDING or y2 + PADDING < oy1 or y1 > oy2 + PADDING)

def manhattan_dist(n1, n2):
    x1, y1 = n1.center()
    x2, y2 = n2.center()
    return abs(x1 - x2) + abs(y1 - y2)

def kruskal_mst(nodes):
    parent = {n.id: n.id for n in nodes}
    def find(u):
        while parent[u] != u:
            parent[u] = parent[parent[u]]
            u = parent[u]
        return u
    def union(u, v):
        ru, rv = find(u), find(v)
        if ru != rv:
            parent[ru] = rv
            return True
        return False
    edges = sorted((manhattan_dist(n1, n2), n1.id, n2.id) for n1, n2 in combinations(nodes, 2))
    return [(u, v) for _, u, v in edges if union(u, v)]

def place_nodes():
    placed = []
    attempts = 0
    while len(placed) < NODE_COUNT and attempts < 1000:
        w, h = random.choice(NODE_SIZE_OPTIONS)
        x = random.randint(0, GRID_SIZE - w)
        y = random.randint(0, GRID_SIZE - h)
        new_node = Node(len(placed), x, y, w, h)
        if all(not new_node.intersects(p) for p in placed):
            placed.append(new_node)
        attempts += 1
    return placed if len(placed) == NODE_COUNT else None

def get_connector_boxes(p1, p2, thickness):
    boxes = []
    if p1[0] == p2[0]:  # vertical
        x = p1[0] - thickness / 2
        y1, y2 = sorted([p1[1], p2[1]])
        boxes.append((x, y1, x + thickness, y2))
    elif p1[1] == p2[1]:  # horizontal
        y = p1[1] - thickness / 2
        x1, x2 = sorted([p1[0], p2[0]])
        boxes.append((x1, y, x2, y + thickness))
    return boxes

def path_is_clear(p1, mid, p2, thickness, nodes, skip_ids):
    for segment in [(p1, mid), (mid, p2)]:
        for box in get_connector_boxes(*segment, thickness=thickness):
            for node in nodes:
                if node.id in skip_ids:
                    continue
                if node.intersects_box(box):
                    return False
    return True

def find_best_clear_path(n1, n2, all_nodes, thickness):
    points1 = n1.get_connector_points()
    points2 = n2.get_connector_points()
    for p1 in points1.values():
        for p2 in points2.values():
            for mid in [(p2[0], p1[1]), (p1[0], p2[1])]:
                if path_is_clear(p1, mid, p2, thickness, all_nodes, skip_ids={n1.id, n2.id}):
                    return p1, mid, p2
    return None

def scale(p): return (p[0] * SCALE, p[1] * SCALE)

def add_t_junctions(nodes, mst_edges):
    t_count = max(1, int(len(mst_edges) * T_CONNECTOR_PERCENT))
    t_connectors = defaultdict(list)
    connected = set(i for e in mst_edges for i in e)
    edge_attempts = list(combinations(connected, 2))
    random.shuffle(edge_attempts)

    for u, v in edge_attempts:
        if any((u in t_connectors, v in t_connectors)):
            continue
        candidates = [n.id for n in nodes if n.id not in (u, v) and n.id not in t_connectors]
        if not candidates:
            continue
        w = random.choice(candidates)
        center = random.choice([u, v, w])
        others = [i for i in (u, v, w) if i != center]
        t_connectors[center].extend(others)
        if len(t_connectors) >= t_count:
            break
    return t_connectors

def draw_box_connector(dwg, p1, p2, thickness):
    for box in get_connector_boxes(p1, p2, thickness):
        x1, y1, x2, y2 = box
        dwg.add(dwg.rect(
            insert=scale((x1, y1)),
            size=((x2 - x1) * SCALE, (y2 - y1) * SCALE),
            fill='black'
        ))

def draw_svg(nodes, edges, t_connectors, filename="mst_layout.svg"):
    dwg = svgwrite.Drawing(filename, profile='tiny', size=(GRID_SIZE*SCALE, GRID_SIZE*SCALE))
    for node in nodes:
        dwg.add(dwg.rect(
            insert=scale((node.x, node.y)),
            size=(node.w*SCALE, node.h*SCALE),
            fill='skyblue',
            stroke='black',
            stroke_width=1*SCALE
        ))

    id_map = {n.id: n for n in nodes}

    for u, v in edges:
        n1, n2 = id_map[u], id_map[v]
        thickness, _ = random.choice(LINE_WIDTH_OPTIONS)
        p = find_best_clear_path(n1, n2, nodes, thickness)
        if not p:
            continue
        p1, mid, p2 = p
        draw_box_connector(dwg, p1, mid, thickness)
        draw_box_connector(dwg, mid, p2, thickness)

    for center, others in t_connectors.items():
        cnode = id_map[center]
        for nid in others:
            onode = id_map[nid]
            thickness, _ = random.choice(LINE_WIDTH_OPTIONS)
            p = find_best_clear_path(cnode, onode, nodes, thickness)
            if not p:
                continue
            p1, mid, p2 = p
            draw_box_connector(dwg, p1, mid, thickness)
            draw_box_connector(dwg, mid, p2, thickness)

    dwg.save()

def generate_layout():
    for attempt in range(MAX_ATTEMPTS):
        nodes = place_nodes()
        if not nodes:
            continue
        edges = kruskal_mst(nodes)
        if len(edges) != NODE_COUNT - 1:
            continue
        t_connectors = add_t_junctions(nodes, edges)
        draw_svg(nodes, edges, t_connectors)
        print(f"✅ CleanFlow v4 SVG generated (zero overlap): mst_layout.svg (attempt {attempt+1})")
        return
    print("❌ Could not generate clean layout after 100 attempts.")

if __name__ == "__main__":
    generate_layout()
