#!/usr/bin/env python3
"""Encode FarmVille's `text[package][bucket][key]` locale contract as FVL2."""
from __future__ import annotations
import argparse, struct, sys, zlib
from pathlib import Path
from extract_locale_abc import extract_tree, parse_abc, swf_abc_blocks

NONE = 0xffffffff
HEADER = struct.Struct('<4sHHIIIIII')

def encode(tree: dict) -> bytes:
    text = tree['text']; strings=[]; ids={}; packages=[]; buckets=[]; entries=[]; variations=[]
    def sid(value: str | None) -> int:
        if value is None: return NONE
        if value not in ids: ids[value]=len(strings); strings.append(value)
        return ids[value]
    for package in sorted(text):
        bucket_map=text[package]; length=int(bucket_map['length']);
        if length < 1 or length & (length-1): raise ValueError(f'{package}: invalid bucket length {length}')
        first_bucket=len(buckets)
        for bucket in range(length):
            values=bucket_map.get(str(bucket), {}); first_entry=len(entries)
            for key, item in sorted(values.items()):
                if not isinstance(item, dict) or not isinstance(item.get('original'), str): raise ValueError(f'{package}/{key}: invalid locale entry')
                first_variation=len(variations); variant_map=item.get('variations', {})
                for name, value in sorted(variant_map.items()):
                    if not isinstance(value, str): raise ValueError(f'{package}/{key}/{name}: invalid variation')
                    variations.append((sid(name), sid(value)))
                entries.append((sid(key), sum(map(ord, key)) & 0xffffffff, sid(item['original']), sid(item.get('gender')), first_variation, len(variations)-first_variation))
            buckets.append((first_entry, len(entries)-first_entry))
        packages.append((sid(package), length, first_bucket))
    encoded=[value.encode('utf-8') for value in strings]; offsets=[0]
    for value in encoded: offsets.append(offsets[-1]+len(value))
    blob=b''.join(encoded)
    return b''.join((HEADER.pack(b'FVL3',3,0,len(strings),len(packages),len(buckets),len(entries),len(variations),len(blob)), struct.pack(f'<{len(offsets)}I',*offsets),blob, b''.join(struct.pack('<III',*x) for x in packages), b''.join(struct.pack('<II',*x) for x in buckets), b''.join(struct.pack('<IIIIII',*x) for x in entries), b''.join(struct.pack('<II',*x) for x in variations)))

def main() -> int:
    p=argparse.ArgumentParser(); p.add_argument('swf',type=Path); p.add_argument('output',type=Path); p.add_argument('--raw-output',type=Path); a=p.parse_args()
    matches=[x for block in swf_abc_blocks(a.swf) if (x:=parse_abc(block))]
    if len(matches)!=1: raise ValueError(f'expected one Locale_en_US initializer, found {len(matches)}')
    _, code, _, strings, names=matches[0]; data=encode(extract_tree(code,strings,names)['tree']); a.output.parent.mkdir(parents=True,exist_ok=True); a.output.write_bytes(b'FVLZ'+len(data).to_bytes(4,'little')+zlib.compress(data,9));
    if a.raw_output: a.raw_output.parent.mkdir(parents=True,exist_ok=True); a.raw_output.write_bytes(data)
    print(len(data),a.output.stat().st_size); return 0
if __name__=='__main__':
    try: raise SystemExit(main())
    except (OSError,ValueError,zlib.error) as e: print(f'error: {e}',file=sys.stderr); raise SystemExit(1)
