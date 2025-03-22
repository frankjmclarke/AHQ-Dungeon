import os
import random
import math
import matplotlib.pyplot as plt

class Rectangle:
    """A simple rectangle defined by its lower-left corner, width, and height."""
    def __init__(self, x, y, width, height):
        self.x = x
        self.y = y
        self.width = width
        self.height = height

    def get_bounds(self):
        """Return (min_x, min_y, max_x, max_y)."""
        return (self.x, self.y, self.x + self.width, self.y + self.height)

    def intersects(self, other):
        """
        Returns True if this rectangle overlaps the other (i.e. has a positive area of intersection).
        Note: if two rectangles just touch (share an edge) then they do NOT count as overlapping.
        """
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

    def remove_last_door(self):
        if self.doors:
            self.doors.pop()

    def center(self):
        return (self.x + self.width/2, self.y + self.height/2)

class Corridor(Rectangle):
    """A corridor is a rectangle (segment) of fixed thickness."""
    def __init__(self, x, y, width, height, corridor_thickness):
        super().__init__(x, y, width, height)
        self.corridor_thickness = corridor_thickness

class Door(Rectangle):
    """A door is a small rectangle placed on a room’s wall."""
    def __init__(self, x, y, width, height):
        super().__init__(x, y, width, height)

def get_connection_point(room, door, wall, offset=0.1):
    """
    Returns a point on the room’s boundary from which a corridor should start.
    The offset moves the point slightly outside the room so that the corridor does not overlap the room.
    """
    if wall == 'left':
        return (room.x - offset, door.y + door.height/2)
    elif wall == 'right':
        return (room.x + room.width + offset, door.y + door.height/2)
    elif wall == 'top':
        return (door.x + door.width/2, room.y + room.height + offset)
    elif wall == 'bottom':
        return (door.x + door.width/2, room.y - offset)
    else:
        raise ValueError("Invalid wall.")

class MapGenerator:
    """
    Generates a board game map of rectangular rooms connected by corridors.
    
    Rules enforced:
      - A new rectangle (room or corridor) is added only if it does not overlap any existing rectangle.
        (Overlap means positive area of intersection; touching is allowed.)
      - Exception: If allow_corridor_crossings is True, corridor segments may overlap other corridors,
        but corridors may never overlap any room.
    """
    def __init__(self, allow_corridor_crossings=True, corridor_thickness=1):
        self.rooms = []      # All placed Room objects.
        self.corridors = []  # All placed Corridor objects.
        self.allow_corridor_crossings = allow_corridor_crossings
        self.corridor_thickness = corridor_thickness

    def check_no_overlap(self, new_rect, ignore_list=None, new_rect_is_corridor=False):
        """
        Checks that new_rect does not overlap any existing rectangle.
        The new rectangle is checked against all rooms.
        If new_rect is a corridor and allow_corridor_crossings is False, then it is also checked against all corridors.
        The ignore_list (if provided) contains rectangles that are exempt from the check.
        """
        if ignore_list is None:
            ignore_list = []
        # Always check against rooms.
        for r in self.rooms:
            if r in ignore_list:
                continue
            if new_rect.intersects(r):
                return False
        # For corridors, if overlapping corridors are not allowed, check them.
        if not self.allow_corridor_crossings or not new_rect_is_corridor:
            for c in self.corridors:
                if c in ignore_list:
                    continue
                if new_rect.intersects(c):
                    return False
        return True

    def add_room(self, room):
        self.rooms.append(room)

    def add_corridor(self, corridor):
        self.corridors.append(corridor)

    def connect_rooms_with_door(self, room, wall, door_size=(1, 1)):
        """
        Adds a door to the specified wall of the room.
        The door is placed so that its center is at least corridor_thickness/2 away from the room's corners.
        """
        half = self.corridor_thickness / 2.0
        if wall in ['left', 'right']:
            door_y_min = room.y + half
            door_y_max = room.y + room.height - half - door_size[1]
            door_y = room.y + (room.height - door_size[1]) / 2.0 if door_y_min > door_y_max else random.uniform(door_y_min, door_y_max)
            door_x = room.x - door_size[0] if wall == 'left' else room.x + room.width
            door = Door(door_x, door_y, door_size[0], door_size[1])
        elif wall in ['top', 'bottom']:
            door_x_min = room.x + half
            door_x_max = room.x + room.width - half - door_size[0]
            door_x = room.x + (room.width - door_size[0]) / 2.0 if door_x_min > door_x_max else random.uniform(door_x_min, door_x_max)
            door_y = room.y - door_size[1] if wall == 'bottom' else room.y + room.height
            door = Door(door_x, door_y, door_size[0], door_size[1])
        else:
            raise ValueError("Invalid wall; choose from 'left','right','top','bottom'.")
        room.add_door(door)
        return door

    @staticmethod
    def get_connection_walls(room1, room2):
        """
        Chooses which walls to use for door placement based on the centers of room1 and room2.
        Returns a tuple (wall_for_room1, wall_for_room2).
        """
        c1x, c1y = room1.center()
        c2x, c2y = room2.center()
        if abs(c2x - c1x) >= abs(c2y - c1y):
            return ('right', 'left') if c2x >= c1x else ('left', 'right')
        else:
            return ('top', 'bottom') if c2y >= c1y else ('bottom', 'top')

    def add_corridor_connection(self, cp1, cp2, room1, room2):
        """
        Given two connection points (which lie just outside the room boundaries),
        computes two L-shaped corridor options (with a shared corner) and returns the first option
        that does not overlap any existing rectangle.
        """
        half = self.corridor_thickness / 2.0
        xdiff = abs(cp1[0] - cp2[0])
        ydiff = abs(cp1[1] - cp2[1])
        options = []

        # If connection points are aligned vertically.
        if xdiff < 1e-6:
            x = cp1[0] - half
            y = min(cp1[1], cp2[1])
            seg = Corridor(x, y, self.corridor_thickness, ydiff, self.corridor_thickness)
            options.append([seg])
        # If aligned horizontally.
        elif ydiff < 1e-6:
            y = cp1[1] - half
            x = min(cp1[0], cp2[0])
            seg = Corridor(x, y, xdiff, self.corridor_thickness, self.corridor_thickness)
            options.append([seg])
        else:
            # Option 1: Horizontal then vertical.
            # Common corner at (cp2[0], cp1[1])
            hor_x = min(cp1[0], cp2[0])
            hor_width = xdiff
            hor_y = cp1[1] - half
            hor_seg = Corridor(hor_x, hor_y, hor_width, self.corridor_thickness, self.corridor_thickness)
            vert_x = cp2[0] - half
            # Align vertical segment so its edge touches the horizontal segment.
            vert_y = cp1[1] - half if cp2[1] >= cp1[1] else cp2[1] - half
            vert_seg = Corridor(vert_x, vert_y, self.corridor_thickness, ydiff, self.corridor_thickness)
            options.append([hor_seg, vert_seg])
            # Option 2: Vertical then horizontal.
            # Common corner at (cp1[0], cp2[1])
            vert_x2 = cp1[0] - half
            vert_seg2 = Corridor(vert_x2, min(cp1[1], cp2[1]) - half, self.corridor_thickness, ydiff, self.corridor_thickness)
            hor_x2 = min(cp1[0], cp2[0])
            hor_seg2 = Corridor(hor_x2, cp2[1] - half, xdiff, self.corridor_thickness, self.corridor_thickness)
            options.append([vert_seg2, hor_seg2])

        # For each option, check each segment against all existing rectangles.
        for segs in options:
            valid = True
            for seg in segs:
                # Check against every room (rooms must never be overlapped)
                for r in self.rooms:
                    # Allow touching if the corridor is exactly adjacent to the room boundary.
                    if seg.intersects(r):
                        valid = False
                        break
                if not valid:
                    break
                # Check against corridors if corridor crossings are not allowed.
                if not self.allow_corridor_crossings:
                    for c in self.corridors:
                        if seg.intersects(c):
                            valid = False
                            break
                if not valid:
                    break
            if valid:
                return segs
        return None

    def generate_map(self, n_rooms=5, max_room_attempts=100, max_corridor_attempts=10):
        """
        Generates a set of rooms (without any overlap) and connects them via corridors.
        For each connection (based on a minimum spanning tree) up to max_corridor_attempts are made
        to try different door placements until a corridor connection is found that obeys the overlap rule.
        """
        self.rooms = []
        self.corridors = []
        sizes = [(2, 2), (4, 6)]
        room_count = 0
        attempts = max_room_attempts
        # Place rooms without overlapping any existing room.
        while room_count < n_rooms and attempts > 0:
            width, height = random.choice(sizes)
            x = random.randint(0, 15)
            y = random.randint(0, 15)
            candidate = Room(x, y, width, height)
            if not any(candidate.intersects(r) for r in self.rooms):
                self.add_room(candidate)
                room_count += 1
            attempts -= 1
        if room_count < n_rooms:
            print("Warning: Only", room_count, "rooms were placed without overlap.")

        if len(self.rooms) < 2:
            return

        # Build a minimum spanning tree (MST) to connect all rooms.
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

        # For each MST edge, attempt to connect rooms with doors and a corridor.
        for (i, j) in mst_edges:
            room1 = self.rooms[i]
            room2 = self.rooms[j]
            wall1, wall2 = MapGenerator.get_connection_walls(room1, room2)
            success = False
            for attempt in range(max_corridor_attempts):
                door1 = self.connect_rooms_with_door(room1, wall1, door_size=(1, 1))
                door2 = self.connect_rooms_with_door(room2, wall2, door_size=(1, 1))
                cp1 = get_connection_point(room1, door1, wall1)  # Connection point just outside room1.
                cp2 = get_connection_point(room2, door2, wall2)  # Connection point just outside room2.
                corridor_segs = self.add_corridor_connection(cp1, cp2, room1, room2)
                if corridor_segs is not None:
                    # Final check: each new corridor segment must pass the no-overlap test.
                    valid = all(self.check_no_overlap(seg, ignore_list=[], new_rect_is_corridor=True) for seg in corridor_segs)
                    if valid:
                        for seg in corridor_segs:
                            self.add_corridor(seg)
                        success = True
                        break
                room1.remove_last_door()
                room2.remove_last_door()
            if not success:
                print(f"Failed to connect room {i} and room {j} without overlap.")

    def draw_map(self, filename="map.png"):
        """Draws the map using matplotlib and saves it as a PNG file."""
        fig, ax = plt.subplots(figsize=(8, 8))
        # Draw rooms.
        for room in self.rooms:
            room_rect = plt.Rectangle((room.x, room.y), room.width, room.height,
                                      edgecolor='black', facecolor='lightblue', lw=2)
            ax.add_patch(room_rect)
            # Draw room doors.
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
    # Set allow_corridor_crossings to False so corridors may not overlap any rectangle.
    generator = MapGenerator(allow_corridor_crossings=False, corridor_thickness=1)
    generator.generate_map(n_rooms=5, max_room_attempts=100, max_corridor_attempts=10)
    generator.draw_map()
