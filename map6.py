import random
import svgwrite
import math
from collections import namedtuple
import heapq

# Constants
GRID_WIDTH = 100
GRID_HEIGHT = 100
CELL_SIZE = 4  # Scaled up from 1 to 4
MAX_ATTEMPTS = 100
NODE_COUNT = 10
T_SHAPE_PERCENT = 0.15
TOUCHING_PERCENT = 0.15
NODE_SIZES = [(10, 10), (5, 5)]

# Structures
Rect = namedtuple('Rect', 'x y w h')

# Hash grid for overlap detection
class HashGrid:
    def __init__(self, width, height):
        self.grid = [[None for _ in range(width)] for _ in range(height)]
        self.reserved = set()

    def can_place(self, x, y, w, h):
        return all((x + dx, y + dy) not in self.reserved 
                   for dx in range(w) for dy in range(h)
                   if 0 <= x + dx < GRID_WIDTH and 0 <= y + dy < GRID_HEIGHT)

    def reserve(self, x, y, w, h, marker='node'):
        for dx in range(w):
            for dy in range(h):
                self.reserved.add((x + dx, y + dy))
                self.grid[y + dy][x + dx] = marker

# Node placement with optional touching

def place_nodes(grid, count):
    nodes = []
    touching_count = int(count * TOUCHING_PERCENT)
    placed = 0
    while placed < count:
        for _ in range(MAX_ATTEMPTS):
            w, h = random.choice(NODE_SIZES)
            if placed > 0 and touching_count > 0:
                # Try placing adjacent to an existing node
                base = random.choice(nodes)
                side = random.choice(['top', 'bottom', 'left', 'right'])
                if side == 'top':
                    x = base.x
                    y = base.y - h
                elif side == 'bottom':
                    x = base.x
                    y = base.y + base.h
                elif side == 'left':
                    x = base.x - w
                    y = base.y
                else:  # right
                    x = base.x + base.w
                    y = base.y
                if 0 <= x < GRID_WIDTH - w and 0 <= y < GRID_HEIGHT - h and grid.can_place(x, y, w, h):
                    grid.reserve(x, y, w, h, marker='node')
                    nodes.append(Rect(x, y, w, h))
                    touching_count -= 1
                    placed += 1
                    break
            else:
                x = random.randint(0, GRID_WIDTH - w)
                y = random.randint(0, GRID_HEIGHT - h)
                if grid.can_place(x, y, w, h):
                    grid.reserve(x, y, w, h, marker='node')
                    nodes.append(Rect(x, y, w, h))
                    placed += 1
                    break
        else:
            return None
    return nodes

def node_distance(n1, n2):
    cx1, cy1 = n1.x + n1.w // 2, n1.y + n1.h // 2
    cx2, cy2 = n2.x + n2.w // 2, n2.y + n2.h // 2
    return math.hypot(cx1 - cx2, cy1 - cy2)

def build_mst(nodes):
    edges = []
    parent = list(range(len(nodes)))

    def find(u):
        while parent[u] != u:
            parent[u] = parent[parent[u]]
            u = parent[u]
        return u

    def union(u, v):
        pu, pv = find(u), find(v)
        if pu == pv:
            return False
        parent[pu] = pv
        return True

    for i in range(len(nodes)):
        for j in range(i + 1, len(nodes)):
            dist = node_distance(nodes[i], nodes[j])
            edges.append((dist, i, j))
    edges.sort()

    mst = []
    for dist, u, v in edges:
        if union(u, v):
            mst.append((u, v))

    return mst

def heuristic(a, b):
    return abs(a[0] - b[0]) + abs(a[1] - b[1])

def astar(grid, start, goal):
    open_set = []
    heapq.heappush(open_set, (0 + heuristic(start, goal), 0, start, [start]))
    visited = set()

    while open_set:
        est_total, cost, current, path = heapq.heappop(open_set)
        if current == goal:
            return path

        if current in visited:
            continue
        visited.add(current)

        x, y = current
        for dx, dy in [(-1,0),(1,0),(0,-1),(0,1)]:
            nx, ny = x + dx, y + dy
            if 0 <= nx < GRID_WIDTH and 0 <= ny < GRID_HEIGHT and (nx, ny) not in grid.reserved:
                next_node = (nx, ny)
                if next_node not in visited:
                    new_cost = cost + 1
                    est = new_cost + heuristic(next_node, goal)
                    heapq.heappush(open_set, (est, new_cost, next_node, path + [next_node]))
    return None

def get_connection_points(node):
    x, y, w, h = node
    points = []
    for dx in range(w):
        points.append((x + dx, y - 1))
        points.append((x + dx, y + h))
    for dy in range(h):
        points.append((x - 1, y + dy))
        points.append((x + w, y + dy))
    return points

def route_connection(grid, n1, n2):
    points1 = get_connection_points(n1)
    points2 = get_connection_points(n2)
    for p1 in points1:
        for p2 in points2:
            path = astar(grid, p1, p2)
            if path:
                return path
    return None

for attempt in range(MAX_ATTEMPTS):
    grid = HashGrid(GRID_WIDTH, GRID_HEIGHT)
    nodes = place_nodes(grid, NODE_COUNT)
    if nodes:
        mst = build_mst(nodes)
        break
else:
    raise RuntimeError("Failed to place all nodes after maximum attempts")

connection_paths = []
for u, v in mst:
    n1, n2 = nodes[u], nodes[v]
    path = route_connection(grid, n1, n2)
    if path:
        for cell in path:
            grid.reserved.add(cell)
        connection_paths.append((u, v, path))
    else:
        raise RuntimeError("Failed to route connection")

def find_nearest_unused_node(used_pairs, node_index, total_nodes):
    connected = set()
    for u, v in used_pairs:
        connected.add(u)
        connected.add(v)
    candidates = [i for i in range(total_nodes) if i != node_index and i not in connected]
    if not candidates:
        return None
    candidates.sort(key=lambda i: node_distance(nodes[node_index], nodes[i]))
    return candidates[0] if candidates else None

num_t_shapes = max(1, round(len(mst) * T_SHAPE_PERCENT))
t_shape_paths = []
used_t_nodes = set()
random.shuffle(connection_paths)

for u, v, main_path in connection_paths:
    if len(t_shape_paths) >= num_t_shapes:
        break
    base_node = random.choice([u, v])
    t_node = find_nearest_unused_node(mst + t_shape_paths, base_node, len(nodes))
    if t_node is None or t_node in used_t_nodes:
        continue
    third = nodes[t_node]
    third_path = route_connection(grid, nodes[base_node], third)
    if third_path:
        for cell in third_path:
            grid.reserved.add(cell)
        t_shape_paths.append((base_node, t_node, third_path))
        used_t_nodes.add(t_node)

all_paths = connection_paths + t_shape_paths

def render_svg(filename, nodes, paths):
    dwg = svgwrite.Drawing(filename, profile='tiny', size=(f'{GRID_WIDTH * CELL_SIZE}px', f'{GRID_HEIGHT * CELL_SIZE}px'))
    for idx, node in enumerate(nodes):
        x, y = node.x * CELL_SIZE, node.y * CELL_SIZE
        w, h = node.w * CELL_SIZE, node.h * CELL_SIZE
        dwg.add(dwg.rect(insert=(x, y), size=(w, h), fill='lightblue', stroke='black', stroke_width=0.5))
        dwg.add(dwg.text(
            str(idx + 1),
            insert=(x + w / 2, y + h / 2 + 3),  # Manual vertical adjustment
            text_anchor="middle",
            font_size=8
        ))
    for u, v, path in paths:
        path_line = [(x * CELL_SIZE + CELL_SIZE / 2, y * CELL_SIZE + CELL_SIZE / 2) for x, y in path]
        dwg.add(dwg.polyline(points=path_line, stroke='red', fill='none', stroke_width=0.5))
    dwg.save()

output_path = 'mst_layout.svg'
render_svg(output_path, nodes, all_paths)