import random
import math

# Utility: Check if two ranges overlap.
def overlap_range(a_start, a_end, b_start, b_end):
    return not (a_end <= b_start or b_end <= a_start)

# --- Data Classes ---

class Room:
    def __init__(self, room_id, x, y, width, height):
        self.id = room_id
        self.x = x      # top-left x
        self.y = y      # top-left y
        self.width = width
        self.height = height
        self.doors = [] # list of (x, y) door centers

    @property
    def right(self):
        return self.x + self.width

    @property
    def bottom(self):
        return self.y + self.height

class Corridor:
    def __init__(self, corridor_id, x, y, width, height, room_a_id, room_b_id):
        self.id = corridor_id
        self.x = x  # top-left x
        self.y = y  # top-left y
        self.width = width
        self.height = height
        self.room_a_id = room_a_id
        self.room_b_id = room_b_id

class MapLayout:
    def __init__(self, width, height):
        self.width = width
        self.height = height
        self.rooms = []      # list of Room objects
        self.corridors = []  # list of Corridor objects

    def render_svg(self, filename="map.svg"):
        svg = []
        svg.append(f'<svg xmlns="http://www.w3.org/2000/svg" width="{self.width}" height="{self.height}">')
        for room in self.rooms:
            svg.append(f'<rect x="{room.x}" y="{room.y}" width="{room.width}" height="{room.height}" fill="none" stroke="black" stroke-width="2" />')
            # Draw door centers as green circles.
            for (dx, dy) in room.doors:
                svg.append(f'<circle cx="{dx}" cy="{dy}" r="2" fill="green" />')
        for corr in self.corridors:
            svg.append(f'<rect x="{corr.x}" y="{corr.y}" width="{corr.width}" height="{corr.height}" fill="none" stroke="blue" stroke-width="2" />')
        svg.append('</svg>')
        with open(filename, "w") as f:
            f.write("\n".join(svg))
        print(f"SVG map rendered to {filename}")

# --- Map Generator ---

class MapGenerator:
    def __init__(self, map_width, map_height, room_configs, corridor_thickness=20, num_rooms=10, seed=None):
        self.map_width = map_width
        self.map_height = map_height
        self.room_configs = room_configs  # list of (width, height) tuples
        self.corridor_thickness = corridor_thickness  # T
        self.num_rooms = num_rooms
        if seed is not None:
            random.seed(seed)
        self.layout = MapLayout(map_width, map_height)
        self.next_id = 1
        # Overlap for corridor: how much to extend the corridor rectangle into a room.
        self.epsilon = 1.0

    def gen_room_id(self):
        id_ = self.next_id
        self.next_id += 1
        return id_

    def place_rooms(self):
        count = 0
        attempts = 0
        while count < self.num_rooms and attempts < self.num_rooms * 100:
            w, h = random.choice(self.room_configs)
            x = random.randint(0, self.map_width - w)
            y = random.randint(0, self.map_height - h)
            new_room = Room(self.gen_room_id(), x, y, w, h)
            if any(self.rooms_intersect(new_room, r) for r in self.layout.rooms):
                attempts += 1
                continue
            self.layout.rooms.append(new_room)
            count += 1
            attempts += 1
        print(f"Placed {len(self.layout.rooms)} rooms.")

    def rooms_intersect(self, r1, r2):
        return not (r1.x >= r2.right or r1.right <= r2.x or r1.y >= r2.bottom or r1.bottom <= r2.y)

    # For simplicity, we connect each room to its nearest neighbor.
    def connect_rooms(self):
        rooms = self.layout.rooms
        connected = set()
        for i, room in enumerate(rooms):
            # Find the closest room that is not yet connected.
            dmin = float('inf')
            best = None
            for j, other in enumerate(rooms):
                if room == other:
                    continue
                d = math.hypot(room.center[0] - other.center[0], room.center[1] - other.center[1])
                if d < dmin:
                    dmin = d
                    best = other
            if best:
                self.add_connection(room, best)
                connected.add((room.id, best.id))
        print(f"Connected {len(connected)} room pairs.")

    def add_connection(self, room_a, room_b):
        T = self.corridor_thickness
        safe = T / 2.0  # door must be in [edge + safe, edge_opposite - safe]
        # Decide whether connection is horizontal or vertical based on centers.
        ax, ay = room_a.x, room_a.y
        bx, by = room_b.x, room_b.y
        if abs(room_a.center[0] - room_b.center[0]) >= abs(room_a.center[1] - room_b.center[1]):
            # Connect horizontally.
            # Order left/right.
            if room_a.x < room_b.x:
                left, right = room_a, room_b
            else:
                left, right = room_b, room_a
            # Allowed vertical range for door in left room is [left.y + safe, left.bottom - safe]
            # And similarly for right.
            low = max(left.y + safe, right.y + safe)
            high = min(left.bottom - safe, right.bottom - safe)
            door_y = random.uniform(low, high) if high > low else (low + high)/2.0
            # Place door on left wall of right room and on right wall of left room.
            door_x_left = left.right
            door_x_right = right.x
            left.doors.append((door_x_left, door_y))
            right.doors.append((door_x_right, door_y))
            # If there is a gap, draw corridor that overlaps into each room by epsilon.
            gap = right.x - left.right
            if gap > 0:
                corr_x = left.right - self.epsilon
                corr_width = gap + 2 * self.epsilon
            else:
                corr_x = left.right
                corr_width = 0
            corr_y = door_y - T/2.0
            self.layout.corridors.append(Corridor(self.gen_room_id(), corr_x, corr_y, corr_width, T, left.id, right.id))
        else:
            # Connect vertically.
            if room_a.y < room_b.y:
                top, bottom = room_a, room_b
            else:
                top, bottom = room_b, room_a
            low = max(top.x + safe, bottom.x + safe)
            high = min(top.x + top.width - safe, bottom.x + bottom.width - safe)
            door_x = random.uniform(low, high) if high > low else (low + high)/2.0
            door_y_top = top.bottom
            door_y_bottom = bottom.y
            top.doors.append((door_x, door_y_top))
            bottom.doors.append((door_x, door_y_bottom))
            gap = bottom.y - top.bottom
            if gap > 0:
                corr_y = top.bottom - self.epsilon
                corr_height = gap + 2 * self.epsilon
            else:
                corr_y = top.bottom
                corr_height = 0
            corr_x = door_x - T/2.0
            self.layout.corridors.append(Corridor(self.gen_room_id(), corr_x, corr_y, T, corr_height, top.id, bottom.id))

    def generate_map(self):
        self.place_rooms()
        if len(self.layout.rooms) < 2:
            print("Not enough rooms.")
            return self.layout
        self.connect_rooms()
        return self.layout

    @property
    def layout(self):
        return self._layout

    @layout.setter
    def layout(self, val):
        self._layout = val

# --- Main Usage ---
if __name__ == "__main__":
    MAP_WIDTH = 500
    MAP_HEIGHT = 500
    room_configs = [(40, 40), (60, 30), (50, 50), (30, 60)]
    # corridor_thickness=20 → door centers must be in [room_edge+10, room_edge_opposite-10]
    generator = MapGenerator(MAP_WIDTH, MAP_HEIGHT, room_configs, corridor_thickness=20, num_rooms=10, seed=59)
    layout = generator.generate_map()
    layout.render_svg("generated_map.svg")
