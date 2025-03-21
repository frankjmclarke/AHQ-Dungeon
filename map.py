import random
import math

# ---------- Utility Functions ----------

def overlap_range(a_start, a_end, b_start, b_end):
    """Return True if the ranges [a_start, a_end] and [b_start, b_end] overlap."""
    return not (a_end <= b_start or b_end <= a_start)

# ---------- Data Classes ----------

class Room:
    def __init__(self, room_id, x, y, width, height):
        self.id = room_id
        self.x = x          # top-left x-coordinate
        self.y = y          # top-left y-coordinate
        self.width = width
        self.height = height
        # Doors will be stored as (x, y) positions (for simplicity)
        self.doors = []     

    @property
    def center(self):
        return (self.x + self.width/2, self.y + self.height/2)

    def right(self):
        return self.x + self.width

    def bottom(self):
        return self.y + self.height

    def intersects(self, other):
        # Check for bounding-box intersection (rooms are assumed axis-aligned)
        if (self.x < other.x + other.width and self.x + self.width > other.x and
            self.y < other.y + other.height and self.y + self.height > other.y):
            return True
        return False

    def is_adjacent_horizontal(self, other):
        """Returns True if self and other share a vertical wall."""
        # Check if self is to the left of other and they touch
        if math.isclose(self.right(), other.x, abs_tol=1e-5):
            # They must have some vertical overlap
            if overlap_range(self.y, self.bottom(), other.y, other.bottom()):
                return True
        # Or self is to the right of other.
        if math.isclose(other.right(), self.x, abs_tol=1e-5):
            if overlap_range(self.y, self.bottom(), other.y, other.bottom()):
                return True
        return False

    def is_adjacent_vertical(self, other):
        """Returns True if self and other share a horizontal wall."""
        if math.isclose(self.bottom(), other.y, abs_tol=1e-5):
            if overlap_range(self.x, self.right(), other.x, other.right()):
                return True
        if math.isclose(other.bottom(), self.y, abs_tol=1e-5):
            if overlap_range(self.x, self.right(), other.x, other.right()):
                return True
        return False

class Corridor:
    def __init__(self, corridor_id, x, y, width, height, room_a_id, room_b_id):
        self.id = corridor_id
        self.x = x          # top-left x-coordinate
        self.y = y          # top-left y-coordinate
        self.width = width
        self.height = height
        self.room_a_id = room_a_id
        self.room_b_id = room_b_id

class MapLayout:
    def __init__(self, width, height):
        self.width = width      # overall canvas width
        self.height = height    # overall canvas height
        self.rooms = []         # list of Room objects
        self.corridors = []     # list of Corridor objects

    def render_svg(self, filename="map.svg"):
        """Renders the layout as an SVG file."""
        svg_elements = []
        # SVG header
        svg_elements.append(f'<svg xmlns="http://www.w3.org/2000/svg" width="{self.width}" height="{self.height}">')
        
        # Draw rooms (with a fill and stroke)
        for room in self.rooms:
            svg_elements.append(
                f'<rect x="{room.x}" y="{room.y}" width="{room.width}" height="{room.height}" fill="none" stroke="black" stroke-width="2" />'
            )
            # Optionally, add room id label at the center.
            cx, cy = room.center
            svg_elements.append(f'<text x="{cx}" y="{cy}" font-size="10" text-anchor="middle" fill="red">{room.id}</text>')
        
        # Draw corridors (in a different stroke color)
        for corr in self.corridors:
            svg_elements.append(
                f'<rect x="{corr.x}" y="{corr.y}" width="{corr.width}" height="{corr.height}" fill="none" stroke="blue" stroke-width="2" />'
            )
        
        # Draw doors (small circles)
        door_radius = 2
        for room in self.rooms:
            for (dx, dy) in room.doors:
                svg_elements.append(
                    f'<circle cx="{dx}" cy="{dy}" r="{door_radius}" fill="green" />'
                )
        
        svg_elements.append('</svg>')
        
        # Write to file
        with open(filename, "w") as f:
            f.write("\n".join(svg_elements))
        print(f"SVG map rendered to {filename}")

# ---------- MST Helper (Union-Find) ----------

class UnionFind:
    def __init__(self, elements):
        self.parent = {e: e for e in elements}
    
    def find(self, a):
        while self.parent[a] != a:
            a = self.parent[a]
        return a
    
    def union(self, a, b):
        root_a = self.find(a)
        root_b = self.find(b)
        if root_a == root_b:
            return False
        self.parent[root_b] = root_a
        return True

# ---------- Map Generator ----------

class MapGenerator:
    def __init__(self, map_width, map_height, room_configs, corridor_thickness=1, num_rooms=10, seed=None):
        """
        room_configs: list of tuples (room_width, room_height)
        corridor_thickness: thickness (in units) for corridors (and door marker alignment)
        num_rooms: number of rooms to attempt placing
        """
        self.map_width = map_width
        self.map_height = map_height
        self.room_configs = room_configs
        self.corridor_thickness = corridor_thickness
        self.num_rooms = num_rooms
        if seed is not None:
            random.seed(seed)
        self.layout = MapLayout(map_width, map_height)
        self.next_id = 1

    def generate_room_id(self):
        room_id = self.next_id
        self.next_id += 1
        return room_id

    def try_place_room(self, room_width, room_height, max_attempts=100):
        """Try to place a room of given dimensions without overlapping existing rooms."""
        for _ in range(max_attempts):
            x = random.randint(0, self.map_width - room_width)
            y = random.randint(0, self.map_height - room_height)
            new_room = Room(self.generate_room_id(), x, y, room_width, room_height)
            if any(new_room.intersects(other) for other in self.layout.rooms):
                continue
            return new_room
        return None

    def place_rooms(self):
        placed = 0
        attempts = 0
        while placed < self.num_rooms and attempts < self.num_rooms * 100:
            room_size = random.choice(self.room_configs)
            room = self.try_place_room(room_size[0], room_size[1])
            if room is not None:
                self.layout.rooms.append(room)
                placed += 1
            attempts += 1
        print(f"Placed {placed} rooms after {attempts} attempts.")

    def build_possible_edges(self):
        """Build list of possible edges between rooms that are horizontally or vertically aligned."""
        edges = []
        rooms = self.layout.rooms
        n = len(rooms)
        for i in range(n):
            for j in range(i+1, n):
                room_a = rooms[i]
                room_b = rooms[j]
                # Check horizontal alignment: vertical ranges overlap.
                if overlap_range(room_a.y, room_a.y+room_a.height, room_b.y, room_b.y+room_b.height):
                    # Use horizontal distance between centers as weight.
                    weight = abs((room_b.center[0]) - (room_a.center[0]))
                    edges.append((weight, room_a, room_b, 'horizontal'))
                # Check vertical alignment: horizontal ranges overlap.
                elif overlap_range(room_a.x, room_a.x+room_a.width, room_b.x, room_b.x+room_b.width):
                    weight = abs((room_b.center[1]) - (room_a.center[1]))
                    edges.append((weight, room_a, room_b, 'vertical'))
        return edges

    def build_mst(self):
        """Use a simple Kruskal algorithm to connect rooms based on possible edges."""
        edges = self.build_possible_edges()
        # Sort edges by weight
        edges.sort(key=lambda e: e[0])
        uf = UnionFind(self.layout.rooms)
        mst_edges = []
        for weight, room_a, room_b, orientation in edges:
            if uf.union(room_a, room_b):
                mst_edges.append((room_a, room_b, orientation))
        return mst_edges

    def add_connection(self, room_a, room_b, orientation):
        """Connect two rooms. If they are adjacent, add a door; otherwise, add a corridor with doors on both ends."""
        # For horizontal connections:
        if orientation == 'horizontal':
            # Determine left and right rooms (by x-coordinate)
            if room_a.x < room_b.x:
                left, right = room_a, room_b
            else:
                left, right = room_b, room_a
            gap = right.x - left.right()
            # If gap is zero, rooms share a wall: add door at the shared wall.
            door_y = int(max(left.y, right.y) + min(left.height, right.height) / 2)
            if math.isclose(gap, 0, abs_tol=1e-5):
                door_x = left.right()  # shared wall
                left.doors.append((door_x, door_y))
                right.doors.append((door_x, door_y))
            else:
                # Rooms are separated by a gap: add door on right side of left room and left side of right room,
                # then create a corridor that spans the gap.
                door_left = (left.right(), door_y)
                door_right = (right.x, door_y)
                left.doors.append(door_left)
                right.doors.append(door_right)
                # Corridor rectangle: horizontal corridor from door_left to door_right with fixed thickness.
                corridor_x = left.right()
                corridor_y = door_y - self.corridor_thickness//2
                corridor_width = gap
                corridor_height = self.corridor_thickness
                corridor = Corridor(self.generate_room_id(), corridor_x, corridor_y, corridor_width, corridor_height, left.id, right.id)
                self.layout.corridors.append(corridor)
        elif orientation == 'vertical':
            # Determine top and bottom rooms (by y-coordinate)
            if room_a.y < room_b.y:
                top, bottom = room_a, room_b
            else:
                top, bottom = room_b, room_a
            gap = bottom.y - top.bottom()
            door_x = int(max(top.x, bottom.x) + min(top.width, bottom.width) / 2)
            if math.isclose(gap, 0, abs_tol=1e-5):
                door_y = top.bottom()  # shared wall
                top.doors.append((door_x, door_y))
                bottom.doors.append((door_x, door_y))
            else:
                door_top = (door_x, top.bottom())
                door_bottom = (door_x, bottom.y)
                top.doors.append(door_top)
                bottom.doors.append(door_bottom)
                # Corridor rectangle: vertical corridor from door_top to door_bottom.
                corridor_y = top.bottom()
                corridor_x = door_x - self.corridor_thickness//2
                corridor_height = gap
                corridor_width = self.corridor_thickness
                corridor = Corridor(self.generate_room_id(), corridor_x, corridor_y, corridor_width, corridor_height, top.id, bottom.id)
                self.layout.corridors.append(corridor)

    def connect_rooms(self):
        mst_edges = self.build_mst()
        for room_a, room_b, orientation in mst_edges:
            self.add_connection(room_a, room_b, orientation)
        print(f"Connected {len(mst_edges)} pairs of rooms (MST).")

    def generate_map(self):
        self.place_rooms()
        if len(self.layout.rooms) < 2:
            print("Not enough rooms placed to build connections.")
            return self.layout
        self.connect_rooms()
        return self.layout

# ---------- Main (Example Usage) ----------

if __name__ == "__main__":
    # Define overall map dimensions (in arbitrary units)
    MAP_WIDTH = 500
    MAP_HEIGHT = 500

    # Room configurations: a list of possible room sizes (width, height)
    room_configs = [(100, 50), (50, 50), (50, 50), (30, 60)]
    # Corridor thickness (fixed) 
    corridor_thickness = 20  # e.g., 6 units thick

    # Create the map generator with a desired number of rooms
    generator = MapGenerator(MAP_WIDTH, MAP_HEIGHT, room_configs, corridor_thickness, num_rooms=10, seed=42)
    layout = generator.generate_map()

    # Render to an SVG file that can be printed.
    layout.render_svg("generated_map.svg")
