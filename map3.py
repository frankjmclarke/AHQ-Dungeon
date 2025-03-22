# -*- coding: utf-8 -*-

import random
import os
import sys

# --- Helper functions for rectangle overlap ---
def rects_overlap(r1, r2):
    # r = (x, y, width, height)
    x1, y1, w1, h1 = r1
    x2, y2, w2, h2 = r2
    return not (x1 + w1 <= x2 or x2 + w2 <= x1 or y1 + h1 <= y2 or y2 + h2 <= y1)

def check_no_overlap(new_rect, rects):
    for r in rects:
        if rects_overlap(new_rect, r):
            return False
    return True

# --- Connection placement helper ---
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
        connection_rects = [h_seg_rect, v_seg_rect]
        return child_rect, connection_rects
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
        connection_rects = [v_seg_rect, h_seg_rect]
        return child_rect, connection_rects

# --- Tree layout generation ---
def generate_tree_layout(num_nodes, allowed_node_sizes, allowed_segments):
    nodes = {}
    edges = []
    # Place the root
    root_size = random.choice(allowed_node_sizes)
    root_pos = (100, 100)
    nodes[0] = {'rect': (root_pos[0], root_pos[1], root_size[0], root_size[1]), 'parent': None}
    
    for i in range(1, num_nodes):
        parent = random.randint(0, i - 1)
        nodes[i] = {'parent': parent, 'size': random.choice(allowed_node_sizes)}
    
    all_rects = [nodes[0]['rect']]
    
    for i in range(1, num_nodes):
        parent_rect = nodes[nodes[i]['parent']]['rect']
        child_size = nodes[i]['size']
        child_rect, connection_rects = place_child(parent_rect, child_size, allowed_segments)
        if not check_no_overlap(child_rect, all_rects):
            return None, None
        for seg in connection_rects:
            if not check_no_overlap(seg, all_rects):
                return None, None
        nodes[i]['rect'] = child_rect
        all_rects.append(child_rect)
        for seg in connection_rects:
            all_rects.append(seg)
        edges.append((nodes[i]['parent'], i, connection_rects))
    return nodes, edges

def generate_valid_layout(num_nodes, allowed_node_sizes, allowed_segments, max_attempts=133100):
    for attempt in range(max_attempts):
        result = generate_tree_layout(num_nodes, allowed_node_sizes, allowed_segments)
        if result[0] is not None:
            print(f"Valid layout generated on attempt {attempt + 1}", flush=True)
            return result
    return None, None

# --- SVG generation ---
def generate_svg(nodes, edges):
    all_rects = []
    for node in nodes.values():
        all_rects.append(node['rect'])
    for (_, _, segs) in edges:
        for seg in segs:
            all_rects.append(seg)
    min_x = min(r[0] for r in all_rects)
    min_y = min(r[1] for r in all_rects)
    max_x = max(r[0] + r[2] for r in all_rects)
    max_y = max(r[1] + r[3] for r in all_rects)
    width = max_x - min_x + 20
    height = max_y - min_y + 20

    svg_lines = []
    svg_lines.append(f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}">')
    for i, node in nodes.items():
        x, y, w, h = node['rect']
        adj_x = x - min_x + 10
        adj_y = y - min_y + 10
        svg_lines.append(f'<rect x="{adj_x}" y="{adj_y}" width="{w}" height="{h}" fill="lightblue" stroke="black" />')
        svg_lines.append(f'<text x="{adj_x + w/2}" y="{adj_y + h/2}" text-anchor="middle" dominant-baseline="middle" font-size="10">{i}</text>')
    for parent, child, segs in edges:
        for seg in segs:
            x, y, w, h = seg
            adj_x = x - min_x + 10
            adj_y = y - min_y + 10
            svg_lines.append(f'<rect x="{adj_x}" y="{adj_y}" width="{w}" height="{h}" fill="black" />')
    svg_lines.append('</svg>')
    return "\n".join(svg_lines)

# --- Main function ---
def main():
    print("Starting MST SVG generation...", flush=True)
    num_nodes = 14
 #   random.seed(2242) 
    allowed_node_sizes = [(20,20), (30,20), (20,30), (40,30), (30,40)]
    allowed_segments = [(30,10), (40,10), (50,10), (30,10), (40,10), (150,10)]
    
    nodes, edges = generate_valid_layout(num_nodes, allowed_node_sizes, allowed_segments)
    if nodes is None:
        print("Failed to generate a non-overlapping layout after 100 attempts.", flush=True)
        return
    svg_output = generate_svg(nodes, edges)
    print("Generated SVG output:\n", svg_output, flush=True)
    
    output_path = r"C:\dev\map\mst_output.svg"
    try:
        os.makedirs(os.path.dirname(output_path), exist_ok=True)
        with open(output_path, "w") as f:
            f.write(svg_output)
        print(f"SVG file successfully written to {output_path}", flush=True)
    except Exception as e:
        print("Error writing file:", e, flush=True)

# Ensure main() is executed if this script is run directly.
if __name__ == "__main__":
    main()
