import csv
import re

# Pattern to detect header marker lines
header_pattern = re.compile(r"^(CH|C|E)(\s|$)")

def contains_digit_outside(text):
    inside_paren = False
    for ch in text:
        if ch == '(':
            inside_paren = True
        elif ch == ')':
            inside_paren = False
        elif not inside_paren and ch.isdigit():
            return True
    return False

def parse_single_header_line(line):
    """
    If the header marker line contains text after the marker and a numeric digit,
    this function extracts the first field from that line and splits out the next 10 tokens.
    """
    line = line.rstrip('\n')
    match = header_pattern.match(line)
    if not match:
        return None
    remainder = line[len(match.group(1)):].lstrip()
    
    inside_paren = False
    first_field_chars = []
    stop_index = None
    for i, ch in enumerate(remainder):
        if ch == '(':
            inside_paren = True
        elif ch == ')':
            inside_paren = False
        elif not inside_paren and ch.isdigit():
            stop_index = i
            break
        first_field_chars.append(ch)
    if stop_index is None:
        stop_index = len(remainder)
    first_field = "".join(first_field_chars).strip()
    
    header_remaining = remainder[stop_index:].strip()
    tokens = header_remaining.split()[:10]
    return [first_field] + tokens

def read_header(lines, start_index):
    """
    Reads a header record starting at lines[start_index]. If the header marker line
    does not include a numeric digit (outside parentheses), then subsequent lines
    are accumulated until a line starting with a digit is encountered.
    
    Returns a tuple (header_fields, next_index) where header_fields is a list of
    fields for the header (first field and then 10 tokens) and next_index is the index
    of the next line after the header.
    """
    header_line = lines[start_index].rstrip('\n')
    match = header_pattern.match(header_line)
    if not match:
        return None, start_index + 1
    
    initial_text = header_line[len(match.group(1)):].strip()
    if initial_text and contains_digit_outside(initial_text):
        header_fields = parse_single_header_line(header_line)
        return header_fields, start_index + 1
    else:
        accumulator = []
        if initial_text:
            accumulator.append(initial_text)
        i = start_index + 1
        token_line = None
        while i < len(lines):
            candidate = lines[i].rstrip('\n')
            if header_pattern.match(candidate):
                break
            stripped = candidate.lstrip()
            if stripped and stripped[0].isdigit():
                token_line = candidate
                i += 1
                break
            else:
                accumulator.append(candidate)
                i += 1
        first_field = " ".join(accumulator).strip()
        tokens = token_line.split()[:10] if token_line is not None else []
        header_fields = [first_field] + tokens
        return header_fields, i

def process_file(input_filename, output_filename):
    with open(input_filename, encoding='utf-8') as f:
        lines = f.readlines()
    
    records = []
    i = 0
    while i < len(lines):
        line = lines[i].rstrip('\n')
        if header_pattern.match(line):
            header_fields, next_index = read_header(lines, i)
            current_record = header_fields if header_fields is not None else []
            i = next_index
            # Append additional fields until the next header is encountered.
            while i < len(lines) and not header_pattern.match(lines[i].rstrip('\n')):
                current_record.append(lines[i].rstrip('\n'))
                i += 1
            records.append(current_record)
        else:
            i += 1

    # Remove starting empty fields if any.
    for rec in records:
        if rec and rec[0] == "":
            rec.pop(0)

    # Apply text replacements.
    for rec in records:
        for j in range(len(rec)):
            rec[j] = rec[j].replace("Â½", "½").replace("â€™s", "'s")

    # Sort records by the first field (case-insensitive).
    records.sort(key=lambda r: r[0].lower() if r and r[0] else "")

    # Filter out records that contain "(WD" or "(*" in any field.
    filtered_records = []
    for rec in records:
        skip = False
        for field in rec:
            if "XX(WD" in field or "XX(*" in field:
                skip = True
                break
        if not skip:
            filtered_records.append(rec)

    # For records containing " (AHQ)" or " (TitD)", remove these substrings from all fields.
    final_records = []
    for rec in filtered_records:
        new_rec = []
        for field in rec:
            # Remove the substrings if present.
            field = field.replace(" (AHQ)", "").replace(" (TitD)", "")
            new_rec.append(field)
        final_records.append(new_rec)

    # Write out the CSV file.
    with open(output_filename, 'w', newline='', encoding='utf-8') as csvfile:
        writer = csv.writer(csvfile)
        writer.writerows(final_records)

if __name__ == "__main__":
    input_file = "Skaven Bestiary.txt"
    output_file = "skaven_bestiary.csv"
    process_file(input_file, output_file)
    print("CSV file created:", output_file)
