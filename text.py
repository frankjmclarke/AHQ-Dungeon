import os
import re
import random
import glob
import sys

# Parse a single mini-table from a block of lines
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

# Parse structured named blocks like Bats()
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
    name = lines[0].strip().lower()
    stack = []
    current = []
    parsed_tables = []

    for line in lines[1:]:
        if line.strip().startswith("("):
            stack.append(current)
            current = []
        elif line.strip().startswith(")"):
            parsed = parse_inline_table(current)
            current = stack.pop()
            parsed_tables.append(parsed)
        else:
            current.append(line)

    def resolve_nested():
        outer = parsed_tables[0]
        roll = random.randint(1, 12)
        entry = next((c for r, c in outer if r == roll), None)
        if "&" in entry:
            parts = [p.strip() for p in entry.split("&")]
            output = []
            for part in parts:
                if part.startswith("("):
                    subtable = parsed_tables[1]
                    subroll = random.randint(1, 12)
                    subentry = next((c for r, c in subtable if r == subroll), None)
                    output.append(subentry)
                else:
                    output.append(part)
            return "\n".join(output)
        return entry

    return name, resolve_nested

# Load all .tab files in the current directory
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

# Prevent repeated resolution in the same chain
resolved_stack = set()

def process_and_resolve_text(text, tables, named_rules, depth):
    indent = "  " * depth
    lines = text.splitlines()
    for line in lines:
        line = line.strip()
        if line.startswith('"') and line.endswith('"'):
            print(f"{indent}→ Output: {line[1:-1]}")
        else:
            print(f"{indent}→ Output: {line}")
        matches = re.findall(r'([A-Za-z0-9_\-]+)\(\)', line)
        for match in matches:
            match_lower = match.lower()
            if match_lower in resolved_stack:
                continue  # prevent infinite loops or repeated recursion
            resolved_stack.add(match_lower)
            if match_lower in named_rules:
                result = named_rules[match_lower]()
                process_and_resolve_text(result, tables, named_rules, depth + 1)
            elif match_lower in tables:
                resolve_table(match_lower, tables, named_rules, depth + 1)
            resolved_stack.remove(match_lower)

def resolve_table(name, tables, named_rules={}, depth=0):
    indent = "  " * depth
    name = name.lower()

    if name not in tables:
        print(f"{indent}[Table not found: {name}]")
        return

    table = tables[name]
    roll = random.randint(1, 12)
    entry = next((content for r, content in table if r == roll), None)

    print(f"{indent}Rolled {roll} on {name}: {entry}")

    if not entry:
        print(f"{indent}[No entry for roll {roll}]")
        return

    if entry.startswith('"') and entry.endswith('"'):
        print(f"{indent}→ Output: {entry[1:-1]}")
        return

    if entry.startswith('[[') and entry.endswith(']]'):
        inner = entry[2:-2]
        parts = [p.strip().replace("()", "").lower() for p in inner.split("&")]
        for part in parts:
            resolve_table(part, tables, named_rules, depth + 1)
        return

    process_and_resolve_text(entry, tables, named_rules, depth)

def main():
    if len(sys.argv) < 2:
        print("Usage: python text.py <TableName>")
        return

    user_input = sys.argv[1].lower()
    tables = load_tables()

    named_rules = {}
    raw_blocks = extract_named_blocks()
    for name, block_lines in raw_blocks.items():
        name, fn = parse_named_block(block_lines)
        named_rules[name] = fn

    normalized = user_input.replace("-", "").replace("_", "")
    candidates = [k for k in tables if k.replace("-", "").replace("_", "") == normalized]
    if not candidates:
        print(f"[Table '{user_input}' not found. Available: {', '.join(tables.keys())}]")
        return

    table_name = candidates[0]
    resolve_table(table_name, tables, named_rules)

if __name__ == "__main__":
    main()
