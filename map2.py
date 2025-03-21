import os
import random
import math
import matplotlib.pyplot as plt

class Rectangle:
    """A simple rectangle defined by its lower‐left corner, width, and height."""
    def __init__(self, x, y, width, height):
        self.x = x
        self.y = y
        self.width = width
        self.height = height

    def get_bounds(self):
        """Return the bounding coordinates: (min_x, min_y, max_x, max_y)."""
        return (self.x, self.y, self.x + self.width, self.y + self.height)

    def intersects(self, other):
        """Determine if this rectangle intersects with another."""
        ax1, ay1, ax2, ay2 = self.get_bounds()
        bx1, by1, bx2, by2 = other.get_bounds()
        return not (ax2 <= bx1 or ax1 >= bx2 or ay2 <= by1 or ay1 >= by2)

class Room(Rectangle):
    """A room is a fixed-size rectangle that can have doors."""
    def __init__(self, x, y, width, height):
        super().__init__(x, y, width, height)
        self.doors = []  # List of Door objects attached to this room

    def add_door(self, door):
        self.doors.append(door)

    def center(self):
        return (self.x + self.width / 2, self.y + self.height / 2)

class Corridor(Rectangle):
    """A corridor is a rectangle of fixed size (thickness and length)."""
    def __init__(self, x, y, width, height, corridor_thickness):
        super().__init__(x, y, width, height)
        self.corridor_thickness = corridor_thickness

class Door(Rectangle):
    """A door is a small rectangle positioned on a room’s wall or corridor."""
    def __init__(self, x, y, width, height):
        super().__init__(x, y, width, height)

class MapGenerator:
    """
    Generates a boardgame map consisting of rectangular rooms and corridors.
    Every room is connected (via doors and corridors) to at least one other room.
    
    The generator uses a boolean flag to permit or deny corridor crossings.
    When connecting rooms, the door’s center is placed so that it is at least
    corridor_thickness/2 away from the room corners.
    """
    def __init__(self, allow_corridor_crossings=True, corridor_thickness=1):
        self.rooms = []
        self.corridors = []
        self.allow_corridor_crossings = allow_corridor_crossings
        self.corridor_thickness = corridor_thickness

    def add_room(self, room):
        self.rooms.append(room)

    def can_add_corridor_segments(self, segments):
        """Check if none of the segments intersect existing corridors."""
        for seg in segments:
            for existing in self.corridors:
                if seg.intersects(existing):
                    return False
        return True

    def add_corridor_connection(self, d1_center, d2_center):
        """
        Tries to add an L-shaped corridor connecting two door centers.
        It tries two orderings: horizontal then vertical, and vertical then horizontal.
        If allow_corridor_crossings is False, it only adds the corridor if no forbidden
        crossing occurs. If both orderings fail, it forces the first option to guarantee connectivity.
        """
        xdiff = abs(d1_center[0] - d2_center[0])
        ydiff = abs(d1_center[1] - d2_center[1])
        
        options = []
        # If rooms are aligned horizontally or vertically, only one segment is needed.
        if xdiff < 1e-6:
            # Vertical corridor only.
            x = d1_center[0] - self.corridor_thickness/2
            y = min(d1_center[1], d2_center[1])
            segments = [Corridor(x, y, self.corridor_thickness, ydiff, self.corridor_thickness)]
            options.append(segments)
        elif ydiff < 1e-6:
            # Horizontal corridor only.
            y = d1_center[1] - self.corridor_thickness/2
            x = min(d1_center[0], d2_center[0])
            segments = [Corridor(x, y, xdiff, self.corridor_thickness, self.corridor_thickness)]
            options.append(segments)
        else:
            # Option 1: horizontal then vertical.
            hor_x = min(d1_center[0], d2_center[0])
            hor_width = xdiff
            hor_y = d1_center[1] - self.corridor_thickness/2
            vert_x = d2_center[0] - self.corridor_thickness/2
            vert_y = min(d1_center[1], d2_center[1])
            segments1 = []
            if hor_width > 0:
                segments1.append(Corridor(hor_x, hor_y, hor_width, self.corridor_thickness, self.corridor_thickness))
            if ydiff > 0:
                segments1.append(Corridor(vert_x, vert_y, self.corridor_thickness, ydiff, self.corridor_thickness))
            options.append(segments1)
            
            # Option 2: vertical then horizontal.
            vert_x2 = d1_center[0] - self.corridor_thickness/2
            vert_y2 = min(d1_center[1], d2_center[1])
            hor_x2 = min(d1_center[0], d2_center[0])
            hor_y2 = d2_center[1] - self.corridor_thickness/2
            segments2 = []
            if ydiff > 0:
                segments2.append(Corridor(vert_x2, vert_y2, self.corridor_thickness, ydiff, self.corridor_thickness))
            if xdiff > 0:
                segments2.append(Corridor(hor_x2, hor_y2, xdiff, self.corridor_thickness, self.corridor_thickness))
            options.append(segments2)
        
        # Try each option:
        for segs in options:
            if self.allow_corridor_crossings or self.can_add_corridor_segments(segs):
                self.corridors.extend(segs)
                return True
        # If no option works and corridor crossing is disallowed, force the first option.
        print("Forcing corridor connection despite crossing restrictions.")
        self.corridors.extend(options[0])
        return True

    def connect_rooms_with_door(self, room, wall_side, door_size=(1, 1)):
        """
        Adds a door to the given room on the specified wall.
        The door is placed so that its center is at least corridor_thickness/2 away from the room's corners.
        wall_side should be one of: 'left', 'right', 'top', or 'bottom'.
        """
        corridor_half = self.corridor_thickness / 2.0

        if wall_side in ['left', 'right']:
            door_y_min = room.y + corridor_half
            door_y_max = room.y + room.height - corridor_half - door_size[1]
            if door_y_min > door_y_max:
                door_y = room.y + (room.height - door_size[1]) / 2.0
            else:
                door_y = random.uniform(door_y_min, door_y_max)
            door_x = room.x - door_size[0] if wall_side == 'left' else room.x + room.width
            door = Door(door_x, door_y, door_size[0], door_size[1])
        elif wall_side in ['top', 'bottom']:
            door_x_min = room.x + corridor_half
            door_x_max = room.x + room.width - corridor_half - door_size[0]
            if door_x_min > door_x_max:
                door_x = room.x + (room.width - door_size[0]) / 2.0
            else:
                door_x = random.uniform(door_x_min, door_x_max)
            door_y = room.y - door_size[1] if wall_side == 'bottom' else room.y + room.height
            door = Door(door_x, door_y, door_size[0], door_size[1])
        else:
            raise ValueError("Invalid wall_side value. Choose from 'left', 'right', 'top', 'bottom'.")
        
        room.add_door(door)
        return door

    @staticmethod
    def get_connection_walls(room1, room2):
        """
        Determines which walls to use for door placement when connecting two rooms.
        The choice is based on the relative centers of the rooms.
        """
        c1x, c1y = room1.center()
        c2x, c2y = room2.center()
        if abs(c2x - c1x) >= abs(c2y - c1y):
            if c2x >= c1x:
                return 'right', 'left'
            else:
                return 'left', 'right'
        else:
            if c2y >= c1y:
                return 'top', 'bottom'
            else:
                return 'bottom', 'top'

    def generate_map(self, n_rooms=5):
        """
        Generates a set of rooms and connects them using a minimum spanning tree (MST).
        This ensures every room has at least one door and is connected (directly or via corridors).
        """
        self.rooms = []
        self.corridors = []
        
        # Create rooms with sizes chosen from example sizes.
        sizes = [(2, 2), (4, 6)]
        for i in range(n_rooms):
            width, height = random.choice(sizes)
            x = random.randint(0, 15)
            y = random.randint(0, 15)
            room = Room(x, y, width, height)
            self.add_room(room)
        
        # If there's only one room, there's nothing to connect.
        if len(self.rooms) < 2:
            return

        # Build an MST (using Prim’s algorithm) to connect all rooms.
        n = len(self.rooms)
        connected = {0}
        not_connected = set(range(1, n))
        mst_edges = []
        
        while not_connected:
            best_edge = None
            best_dist = float('inf')
            for i in connected:
                for j in not_connected:
                    c1x, c1y = self.rooms[i].center()
                    c2x, c2y = self.rooms[j].center()
                    d = math.hypot(c2x - c1x, c2y - c1y)
                    if d < best_dist:
                        best_dist = d
                        best_edge = (i, j)
            if best_edge:
                i, j = best_edge
                mst_edges.append(best_edge)
                connected.add(j)
                not_connected.remove(j)
        
        # For each edge in the MST, add door connections and a corridor.
        for (i, j) in mst_edges:
            room1 = self.rooms[i]
            room2 = self.rooms[j]
            wall1, wall2 = MapGenerator.get_connection_walls(room1, room2)
            door1 = self.connect_rooms_with_door(room1, wall1, door_size=(1, 1))
            door2 = self.connect_rooms_with_door(room2, wall2, door_size=(1, 1))
            
            d1_center = (door1.x + door1.width / 2, door1.y + door1.height / 2)
            d2_center = (door2.x + door2.width / 2, door2.y + door2.height / 2)
            self.add_corridor_connection(d1_center, d2_center)

    def draw_map(self, filename="map.png"):
        """
        Draws the generated map with matplotlib, saves it as a PNG file, and prints the file path.
        """
        fig, ax = plt.subplots(figsize=(8, 8))
        
        # Draw rooms and their doors.
        for room in self.rooms:
            room_rect = plt.Rectangle((room.x, room.y), room.width, room.height,
                                      edgecolor='black', facecolor='lightblue', lw=2)
            ax.add_patch(room_rect)
            for door in room.doors:
                door_rect = plt.Rectangle((door.x, door.y), door.width, door.height,
                                          edgecolor='red', facecolor='pink', lw=2)
                ax.add_patch(door_rect)
        
        # Draw corridors.
        for corridor in self.corridors:
            corridor_rect = plt.Rectangle((corridor.x, corridor.y), corridor.width, corridor.height,
                                          edgecolor='green', facecolor='lightgreen', lw=2)
            ax.add_patch(corridor_rect)
        
        ax.set_xlim(-5, 30)
        ax.set_ylim(-5, 30)
        ax.set_aspect('equal')
        plt.title("Board Game Map")
        
        full_path = os.path.abspath(filename)
        plt.savefig(filename)
        plt.close()
        print(f"Map saved as: {full_path}")

if __name__ == "__main__":
    print("Current working directory:", os.getcwd())
    random.seed(42)  # For reproducibility
    # Set allow_corridor_crossings to False to disallow corridors crossing each other.
    generator = MapGenerator(allow_corridor_crossings=False, corridor_thickness=1)
    generator.generate_map(n_rooms=5)
    generator.draw_map()
