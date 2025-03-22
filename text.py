MAX_DEPTH = 50  # Maximum recursion depth
DEFAULT_DICE = "1D12"  # Global default dice; if no block-specific notation is provided
VERBOSE = False  # Global verbosity flag

import os
import re
import random
import glob
import sys

def roll_dice(notation):
    """
    Parse a dice notation like "1D12" or "2D12" and return (total, [individual_rolls]).
    """
    match = re.fullmatch(r'(\d+)[dD](\d+)', notation)
    if match:
        num = int(match.group(1))
        sides = int(match.group(2))
        total = 0
        rolls = []
        for _ in range(num):
            r = random.randint(1, sides)
            rolls.append(r)
            total += r
        return total, rolls
    else:
        r = random.randint(1, 12)
        return r, [r]

def parse_inline_table(lines):
    table = []
    for line in lines:
        line = line.strip()
        match = re.match(r'(\d+)(?:-(\d+))?\s+(.+)', line)
        if match:
            start = int(match.group(1))
            end = int(match.group(2)) if match.group(2) else start
            content = match.group(3).strip()
            for i in range(start, end + 1):
                table.append((i, content))
    return table

def extract_named_blocks():
    blocks = {}
    for filepath in glob.glob("*.tab") + glob.glob("*.txt"):
        with open(filepath, 'r') as f:
            lines = [line.rstrip() for line in f if line.strip() and not line.strip().startswith('#')]
        i = 0
        while i < len(lines):
            line = lines[i]
            if re.match(r'^[A-Za-z_][A-Za-z0-9_\-]*$', line):
                name = line.strip().lower()
                i += 1
                if i < len(lines) and lines[i].strip().startswith('('):
                    depth = 1
                    block_lines = [line, lines[i]]
                    i += 1
                    while i < len(lines) and depth > 0:
                        block_lines.append(lines[i])
                        if '(' in lines[i]:
                            depth += lines[i].count('(')
                        if ')' in lines[i]:
                            depth -= lines[i].count(')')
                        i += 1
                    blocks[name] = block_lines
                else:
                    i += 1
            else:
                i += 1
    return blocks

def parse_named_block(lines):
    """
    Process a named block.
    Expected format (data unmodified):
      BlockName
      (   2D12 1-2    "Spell A"
          3-4    "Spell B"
          5-6    "Spell C"
          ...
      )
    Even if the dice notation (e.g. "2D12") isn’t on its own line,
    we extract it from the first token of the first table line.
    """
    name = lines[0].strip().lower()
    stack = []
    current = []
    parsed_tables = []  # List of tuples: (dice_notation, table)
    dice_notation = None
    for line in lines[1:]:
        if line.strip().startswith("("):
            stack.append(current)
            current = []
        elif line.strip().startswith(")"):
            # Check if current has a dice notation in its first token.
            if current:
                first_line = current[0].strip()
                tokens = first_line.split()
                if tokens and re.fullmatch(r'\d+[dD]\d+', tokens[0]):
                    dice_notation = tokens[0]
                    # Remove that token from the first line:
                    rest = tokens[1:]
                    if rest:
                        current[0] = " ".join(rest)
                    else:
                        current = current[1:]
            parsed = parse_inline_table(current)
            current = stack.pop() if stack else []
            parsed_tables.append((dice_notation, parsed))
            dice_notation = None
        else:
            current.append(line)
    def resolve_nested():
        if not parsed_tables:
            return ""
        # Use the first mini-table (outer table) from the block.
        notation, outer = parsed_tables[0]
        # For example, if the block is "spell" and no notation was found, force "2D12"
        if name == "spell" and (notation is None):
            roll_notation = "2D12"
        else:
            roll_notation = notation if notation is not None else DEFAULT_DICE
        debug_print(f"Using dice notation '{roll_notation}' for block '{name}'")
        has_composite = any("&" in entry for (r, entry) in outer)
        attempts = 0
        entry = None
        while attempts < 10:
            roll, rolls = roll_dice(roll_notation)
            entry = next((c for r, c in outer if r == roll), None)
            debug_print(f"  → [Nested roll in {name}]: Rolled {roll} (rolls: {rolls}) resulting in: {entry}")
            if entry is not None and has_composite and entry.strip().lower() == name:
                attempts += 1
                continue
            break
        if entry and "&" in entry:
            parts = [p.strip() for p in entry.split("&")]
            output = []
            for part in parts:
                if part.lower() == name:
                    continue
                if part.startswith("(") and len(parsed_tables) > 1:
                    notation2, subtable = parsed_tables[1]
                    roll_notation2 = notation2 if notation2 is not None else DEFAULT_DICE
                    subroll, sub_rolls = roll_dice(roll_notation2)
                    subentry = next((c for r, c in subtable if r == subroll), None)
                    output.append(f"[Nested roll in {name} nested]: Rolled {subroll} (rolls: {sub_rolls}) resulting in: {subentry}")
                else:
                    output.append(part)
            return "\n".join(output)
        return entry if entry is not None else ""
    return name, resolve_nested

def load_tables():
    tables = {}
    for filepath in glob.glob("*.tab"):
        name = os.path.splitext(os.path.basename(filepath))[0].lower()
        tables[name] = parse_tab_file(filepath)
    return tables

def parse_tab_file(filename):
    table = []
    with open(filename, 'r') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            match = re.match(r'(\d+)(?:-(\d+))?\s+(.+)', line)
            if match:
                start = int(match.group(1))
                end = int(match.group(2)) if match.group(2) else start
                content = match.group(3).strip()
                for roll in range(start, end + 1):
                    table.append((roll, content))
    return table

# --- Helper printing functions ---
def debug_print(msg):
    if VERBOSE:
        print(msg)

def final_print(indent, msg):
    # In non-verbose mode, print without any leading spaces.
    if VERBOSE:
        print(f"{indent}→ Output: {msg}")
    else:
        print(f"{msg}")

resolved_stack = set()

def process_and_resolve_text(text, tables, named_rules, depth, parent_table=None, current_named=None):
    indent = "  " * depth if VERBOSE else ""
    if depth > MAX_DEPTH:
        debug_print(indent + "[Maximum recursion depth reached]")
        return
    lines = text.splitlines()
    for line in lines:
        line = line.strip()
        match_full = re.fullmatch(r'([A-Za-z0-9_\-]+)\(\)', line)
        if match_full:
            name_candidate = match_full.group(1).lower()
            if name_candidate in named_rules:
                debug_print(f"{indent}→ Resolving named block: {line}")
                result = named_rules[name_candidate]()
                process_and_resolve_text(result, tables, named_rules, depth + 1, parent_table, current_named=name_candidate)
                continue
        if line.lower() in named_rules:
            if current_named is not None and line.lower() == current_named:
                final_print(indent, line)
            else:
                if line.lower() in resolved_stack:
                    debug_print(f"{indent}→ [Cycle detected: {line}]")
                else:
                    resolved_stack.add(line.lower())
                    debug_print(f"{indent}→ Resolving named block: {line}")
                    result = named_rules[line.lower()]()
                    process_and_resolve_text(result, tables, named_rules, depth + 1, parent_table, current_named=line.lower())
                    resolved_stack.remove(line.lower())
            continue
        if line.startswith('"') and line.endswith('"'):
            final_print(indent, line[1:-1])
        else:
            final_print(indent, line)
        matches = re.findall(r'([A-Za-z0-9_\-]+)\(\)', line)
        for match in matches:
            match_lower = match.lower()
            if current_named is not None and match_lower == current_named:
                continue
            if match_lower in resolved_stack:
                continue
            resolved_stack.add(match_lower)
            if match_lower in named_rules:
                result = named_rules[match_lower]()
                process_and_resolve_text(result, tables, named_rules, depth + 1, parent_table, current_named=match_lower)
            elif match_lower in tables:
                resolve_table(match_lower, tables, named_rules, depth + 1)
            resolved_stack.remove(match_lower)

def resolve_table(name, tables, named_rules={}, depth=0):
    indent = "  " * depth if VERBOSE else ""
    name = name.lower()
    if name not in tables:
        debug_print(f"{indent}[Table not found: {name}]")
        return
    table = tables[name]
    roll, rolls = roll_dice(DEFAULT_DICE)
    entry = next((content for r, content in table if r == roll), None)
    debug_print(f"{indent}Rolled {roll} on {name}: {entry} (rolls: {rolls})")
    if not entry:
        debug_print(f"{indent}[No entry for roll {roll}]")
        return
    if entry.startswith('"') and entry.endswith('"'):
        final_print(indent, entry[1:-1])
        return
    if entry.startswith('[[') and entry.endswith(']]'):
        inner = entry[2:-2]
        parts = [p.strip().replace("()", "").lower() for p in inner.split("&")]
        for part in parts:
            resolve_table(part, tables, named_rules, depth + 1)
        return
    process_and_resolve_text(entry, tables, named_rules, depth, parent_table=name, current_named=None)

def main():
    global VERBOSE
    params = sys.argv[1:]
    if "-verbose" in params:
        VERBOSE = True
        params.remove("-verbose")
    if len(params) < 1:
        print("Usage: python map.py <TableName> [<TableName> ...] [-verbose]")
        return
    tables = load_tables()
    named_rules = {}
    raw_blocks = extract_named_blocks()
    for name, block_lines in raw_blocks.items():
        key, fn = parse_named_block(block_lines)
        named_rules[key] = fn
    for user_input in params:
        normalized = user_input.lower().replace("-", "").replace("_", "")
        candidates = [k for k in tables if k.replace("-", "").replace("_", "") == normalized]
        if not candidates:
            print(f"[Table '{user_input}' not found. Available: {', '.join(tables.keys())}]")
        else:
            table_name = candidates[0]
            if VERBOSE:
                print(f"\n--- Resolving table '{user_input}' ---")
            resolve_table(table_name, tables, named_rules)
            if VERBOSE:
                print("\n" + "="*50 + "\n")
    # In non-verbose mode, no extra header, separator, or indentations are printed.

if __name__ == "__main__":
    main()
