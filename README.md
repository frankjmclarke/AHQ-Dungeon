# Advanced Heroquest Dungeon Generator

A web-based dungeon generation system for Advanced Heroquest, featuring dynamic room and passage generation, treasure tables, and monster encounters.

## Code Overview

### Core Files

1. `index.html` - Main web interface
   - Provides UI for dungeon generation
   - Handles user interactions and API calls
   - Features responsive design with mobile support
   - Includes monster stats toggle and hidden treasure reveal functionality

2. `index2.php` - Core PHP backend
   - Handles table resolution and dice rolling
   - Processes named blocks and nested tables
   - Manages CSV output for monster stats
   - Implements cycle detection for recursive table calls
   - Supports subdirectory-based table organization

3. `list_dirs.php` - Directory listing utility
   - Provides available subdirectories for table organization
   - Used by the web interface for directory selection

### Data Structure

#### .tab Files
The system uses .tab files to define generation tables. Each .tab file follows this structure:

```TableName
(   DiceNotation
    RollRange   "Output Text" & AdditionalTables()
)
```

Example from room.tab:
```
Quest
(   2D12
    1-12   "QUEST ROOM"  & "Stairs Down" & Quest-Rooms-Matrix() & Treasure-Chest() & Hidden-Treasure() 
)
```

Key components:
- Table name (e.g., "Quest", "Normal", "Hazard", "Lair")
- Dice notation (e.g., "2D12", "1D6")
- Roll range (e.g., "1-12")
- Output text (in quotes)
- Additional table calls (using & operator)
- Nested table calls (using TableName() syntax)

#### CSV Integration
- `skaven_bestiary.csv` - Contains monster statistics
- Used for generating detailed monster information
- Integrated with the web interface for stat display

### Directory Structure
```
/
├── index.html          # Main web interface
├── index2.php         # Core PHP backend
├── list_dirs.php      # Directory listing utility
├── *.tab              # Generation tables
├── skaven_bestiary.csv # Monster statistics
└── subdirectories/    # Organized table collections
    ├── Dark Beneath the World/
    ├── Faces of Tzeentch/
    ├── Eyes of Chaos/
    └── ...
```

### Key Features

1. Table Resolution
   - Supports nested table calls
   - Handles dice rolling with various notations
   - Implements cycle detection for recursive calls
   - Processes composite entries using & operator

2. Web Interface
   - Responsive design
   - Monster stats toggle
   - Hidden treasure reveal
   - Directory-based table organization
   - CSV integration for monster statistics

3. Data Organization
   - Subdirectory-based table organization
   - Modular table structure
   - Support for multiple table collections

### Usage

1. Web Interface
   - Select a directory (optional)
   - Click generation buttons for different dungeon elements
   - Toggle monster stats as needed
   - Reveal hidden treasure when available

2. Table Creation
   - Create .tab files following the defined structure
   - Use dice notation for roll ranges
   - Implement nested tables using TableName() syntax
   - Combine outputs using & operator

3. Directory Organization
   - Place related tables in subdirectories
   - Use list_dirs.php to manage available directories
   - Organize tables by theme or campaign