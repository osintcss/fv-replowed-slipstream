#!/usr/bin/env python3
"""Locate and audit Locale_en_US's AVM2 initializer without a full decompiler."""

from __future__ import annotations

import argparse
import collections
import json
import struct
import sys
import zlib
from pathlib import Path

from locale_binary import compress_fvl1, encode, inspect


class Reader:
    def __init__(self, data: bytes): self.data, self.pos = data, 0
    def need(self, size: int) -> None:
        if self.pos + size > len(self.data): raise ValueError("truncated input")
    def u8(self) -> int:
        self.need(1); value = self.data[self.pos]; self.pos += 1; return value
    def u16(self) -> int:
        self.need(2); value = struct.unpack_from("<H", self.data, self.pos)[0]; self.pos += 2; return value
    def u32(self) -> int:
        self.need(4); value = struct.unpack_from("<I", self.data, self.pos)[0]; self.pos += 4; return value
    def u30(self) -> int:
        value = 0
        for shift in range(0, 35, 7):
            byte = self.u8(); value |= (byte & 0x7f) << shift
            if not byte & 0x80:
                if value > 0x3fffffff: raise ValueError("invalid U30")
                return value
        raise ValueError("invalid variable-length integer")
    def take(self, size: int) -> bytes:
        self.need(size); result = self.data[self.pos:self.pos + size]; self.pos += size; return result
    def cstring(self) -> bytes:
        end = self.data.find(b"\0", self.pos)
        if end < 0: raise ValueError("unterminated SWF string")
        result = self.data[self.pos:end]; self.pos = end + 1; return result


def swf_abc_blocks(path: Path) -> list[bytes]:
    raw = path.read_bytes()
    if len(raw) < 8 or raw[:3] not in (b"FWS", b"CWS"):
        raise ValueError("expected an unencrypted FWS or CWS SWF")
    body = raw[8:] if raw[:3] == b"FWS" else zlib.decompress(raw[8:])
    reader = Reader(body)
    nbits = reader.u8() >> 3
    reader.take((5 + 4 * nbits + 7) // 8 - 1)
    reader.take(4)  # frame rate and frame count
    blocks: list[bytes] = []
    while reader.pos < len(body):
        header = reader.u16(); code, length = header >> 6, header & 0x3f
        if length == 0x3f: length = reader.u32()
        payload = reader.take(length)
        if code in (72, 82):
            abc = Reader(payload); abc.u32(); abc.cstring(); blocks.append(payload[abc.pos:])
        if code == 0: break
    return blocks


def skip_traits(reader: Reader) -> None:
    for _ in range(reader.u30()):
        reader.u30(); tag = reader.u8(); kind = tag & 0x0f
        if kind in (0, 6):
            reader.u30(); reader.u30(); value = reader.u30()
            if value: reader.u8()
        elif kind in (1, 2, 3): reader.u30(); reader.u30()
        elif kind == 4:
            reader.u30(); reader.u30()
        elif kind == 5:
            reader.u30(); reader.u30()
        else: raise ValueError(f"unknown trait kind {kind}")
        if tag & 0x40:
            for _ in range(reader.u30()): reader.u30()


def skip_method(reader: Reader) -> None:
    count = reader.u30(); reader.u30(); reader.u30(); flags = reader.u8()
    if flags & 0x08:
        for _ in range(reader.u30()): reader.u30(); reader.u8()
    if flags & 0x80:
        for _ in range(count): reader.u30()


def qname(strings: list[str], names: list[tuple[int, tuple[int, ...]]], index: int) -> str:
    if not index: return ""
    kind, fields = names[index]
    if kind in (0x07, 0x0d) and len(fields) == 2: return strings[fields[1]]
    return ""


def parse_abc(data: bytes):
    r = Reader(data); r.u16(); r.u16()
    for _ in range(2):
        count = r.u30()
        if count:
            for _ in range(count - 1): r.u30()
    doubles = r.u30()
    if doubles: r.take((doubles - 1) * 8)
    strings = [""]; count = r.u30()
    for _ in range(max(count - 1, 0)): strings.append(r.take(r.u30()).decode("utf-8", "replace"))
    namespaces = r.u30()
    for _ in range(max(namespaces - 1, 0)): r.u8(); r.u30()
    nssets = r.u30()
    for _ in range(max(nssets - 1, 0)):
        for _ in range(r.u30()): r.u30()
    names: list[tuple[int, tuple[int, ...]]] = [(0, ())]; count = r.u30()
    for _ in range(max(count - 1, 0)):
        kind = r.u8()
        if kind in (0x07, 0x0d): fields = (r.u30(), r.u30())
        elif kind in (0x0f, 0x10): fields = (r.u30(),)
        elif kind in (0x09, 0x0e): fields = (r.u30(), r.u30())
        elif kind in (0x1b, 0x1c): fields = ()
        elif kind == 0x1d: fields = (r.u30(),) + tuple(r.u30() for _ in range(r.u30()))
        else: raise ValueError(f"unknown multiname kind {kind:#x}")
        names.append((kind, fields))
    for _ in range(r.u30()): skip_method(r)
    for _ in range(r.u30()):
        r.u30()
        for _ in range(r.u30()): r.u30(); r.u30()
    class_count = r.u30(); classes: list[tuple[str, int]] = []
    for _ in range(class_count):
        name = qname(strings, names, r.u30()); r.u30(); flags = r.u8()
        if flags & 0x08: r.u30()
        for _ in range(r.u30()): r.u30()
        classes.append((name, r.u30())); skip_traits(r)
    for _ in range(class_count): r.u30(); skip_traits(r)
    for _ in range(r.u30()): r.u30(); skip_traits(r)
    bodies: dict[int, tuple[int, bytes]] = {}
    for _ in range(r.u30()):
        method = r.u30(); max_stack = r.u30(); r.u30(); r.u30(); r.u30(); code = r.take(r.u30())
        for _ in range(r.u30()): r.u30(); r.u30(); r.u30(); r.u30(); r.u30()
        skip_traits(r); bodies[method] = (max_stack, code)
    for name, initializer in classes:
        if name == "Locale_en_US" and initializer in bodies:
            stack, code = bodies[initializer]
            return name, code, stack, strings, names
    return None


ONE_U30 = {0x04,0x05,0x06,0x08,0x2c,0x2d,0x2e,0x2f,0x31,0x40,0x41,0x42,0x49,0x55,0x56,0x58,0x59,0x5a,0x5d,0x5e,0x60,0x61,0x62,0x63,0x66,0x68,0x6a,0x6c,0x6d,0x6e,0x6f,0x80,0x86,0x92,0x94,0xb2,0xc2,0xc3,0xf0,0xf1}
TWO_U30 = {0x43,0x44,0x45,0x46,0x4a,0x4c,0x4e,0x4f}
BRANCH = set(range(0x0c, 0x1b))


def instructions(code: bytes):
    r = Reader(code)
    while r.pos < len(code):
        offset, op = r.pos, r.u8(); args: tuple[int, ...] = ()
        if op in ONE_U30: args = (r.u30(),)
        elif op in TWO_U30: args = (r.u30(), r.u30())
        elif op in BRANCH: r.take(3)
        elif op in (0x24, 0x65): r.take(1)
        elif op == 0x32: r.take(2)
        elif op == 0xef: r.take(1); r.u30(); r.take(1); r.u30()
        elif op == 0x1b:
            r.take(3); count = r.u30()
            r.take(3 * (count + 1))
        yield offset, op, args


def extract_tree(code: bytes, strings: list[str], names) -> dict:
    """Evaluate the literal-only subset used by Locale_en_US's constructor."""
    stack: list[object] = []
    root: dict[str, object] = {}
    opaque = object()
    newobjects = pushstrings = 0
    for offset, op, args in instructions(code):
        if op == 0x2c:
            stack.append(strings[args[0]]); pushstrings += 1
        elif op == 0x55:
            count = args[0]; values = stack[-2 * count:]
            if len(values) != 2 * count: raise ValueError(f"{offset:#x}: stack underflow in newobject")
            del stack[-2 * count:]; obj: dict[str, object] = {}
            for key, value in zip(values[::2], values[1::2]):
                if not isinstance(key, str): raise ValueError(f"{offset:#x}: non-string locale key")
                obj[key] = value
            stack.append(obj); newobjects += 1
        elif op in (0xd0, 0xd1, 0xd2, 0xd3): stack.append(opaque)
        elif op in (0x30, 0x47, 0x48, 0x1d): pass
        elif op == 0x49:
            if stack: stack.pop()
        elif op in (0x61, 0x68):
            if len(stack) < 2: raise ValueError(f"{offset:#x}: stack underflow in setproperty")
            value, target = stack.pop(), stack.pop(); key = qname(strings, names, args[0])
            if target is opaque and key: root[key] = value
            elif isinstance(target, dict) and key: target[key] = value
            else: raise ValueError(f"{offset:#x}: unsupported setproperty target/name")
        elif op == 0x29:
            if stack: stack.pop()
        else:
            raise ValueError(f"{offset:#x}: unsupported AVM2 opcode {op:#x}")
    if not root: raise ValueError("constructor produced no root locale properties")
    return {"tree": root, "pushstrings": pushstrings, "newobjects": newobjects}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__); parser.add_argument("swf", type=Path); parser.add_argument("--json", action="store_true"); parser.add_argument("--output", type=Path); parser.add_argument("--compressed-output", type=Path)
    args = parser.parse_args(); matches = [parsed for block in swf_abc_blocks(args.swf) if (parsed := parse_abc(block))]
    if len(matches) != 1: raise ValueError(f"expected one Locale_en_US initializer, found {len(matches)}")
    name, code, max_stack, strings, names = matches[0]
    result = extract_tree(code, strings, names)
    report = {"class": name, "constructor_bytes": len(code), "declared_max_stack": max_stack, "pushstrings": result["pushstrings"], "newobjects": result["newobjects"], "root_properties": len(result["tree"]), "root_keys": sorted(result["tree"])}
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_bytes(encode(result["tree"]))
        report["output"] = str(args.output)
        report["table"] = inspect(args.output.read_bytes())
    if args.compressed_output:
        args.compressed_output.parent.mkdir(parents=True, exist_ok=True)
        payload = encode(result["tree"])
        args.compressed_output.write_bytes(compress_fvl1(payload))
        report["compressed_output"] = str(args.compressed_output)
        report["compressed_file_bytes"] = args.compressed_output.stat().st_size
    print(json.dumps(report, indent=2) if args.json else report)
    return 0


if __name__ == "__main__":
    try: raise SystemExit(main())
    except (OSError, ValueError, zlib.error) as error: print(f"error: {error}", file=sys.stderr); raise SystemExit(1)
