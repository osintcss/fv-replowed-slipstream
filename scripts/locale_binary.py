#!/usr/bin/env python3
"""Build and validate deterministic compact FarmVille locale tables (FVL1)."""

from __future__ import annotations

import argparse
import hashlib
import json
import struct
import sys
import zlib
from pathlib import Path
from typing import Any

MAGIC = b"FVL1"
VERSION = 1
NONE = 0xFFFFFFFF
HEADER = struct.Struct("<4sHH32sIIII")
NODE = struct.Struct("<III")
EDGE = struct.Struct("<II")


def compress_fvl1(data: bytes) -> bytes:
    """Wrap a validated FVL1 payload in an FVLZ zlib container."""
    inspect(data)
    return b"FVLZ" + struct.pack("<I", len(data)) + zlib.compress(data, level=9)


def normalize(value: Any, path: str = "$") -> dict[str, Any] | str:
    if isinstance(value, str):
        return value
    if not isinstance(value, dict):
        raise ValueError(f"{path}: locale leaves must be strings, got {type(value).__name__}")

    result: dict[str, Any] = {}
    for key in sorted(value):
        if not isinstance(key, str) or not key:
            raise ValueError(f"{path}: locale keys must be non-empty strings")
        result[key] = normalize(value[key], f"{path}.{key}")
    return result


def canonical_json(tree: dict[str, Any] | str) -> bytes:
    return json.dumps(tree, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode("utf-8")


def encode(tree: dict[str, Any]) -> bytes:
    tree = normalize(tree)
    if not isinstance(tree, dict):
        raise ValueError("the locale root must be an object")

    strings: list[str] = []
    string_ids: dict[str, int] = {}
    nodes: list[tuple[int, int, int]] = []
    edges: list[tuple[int, int] | None] = []

    def intern(value: str) -> int:
        index = string_ids.get(value)
        if index is None:
            index = len(strings)
            string_ids[value] = index
            strings.append(value)
        return index

    def visit(value: dict[str, Any] | str) -> int:
        node_index = len(nodes)
        nodes.append((0, 0, NONE))
        if isinstance(value, str):
            nodes[node_index] = (len(edges), 0, intern(value))
            return node_index

        first_edge = len(edges)
        edges.extend([None] * len(value))
        for offset, (key, child) in enumerate(value.items()):
            child_index = visit(child)
            edges[first_edge + offset] = (intern(key), child_index)
        nodes[node_index] = (first_edge, len(value), NONE)
        return node_index

    visit(tree)
    if len(nodes) >= NONE or len(edges) >= NONE or len(strings) >= NONE:
        raise ValueError("locale table exceeds FVL1 index capacity")

    encoded_strings = [value.encode("utf-8") for value in strings]
    offsets = [0]
    for value in encoded_strings:
        offsets.append(offsets[-1] + len(value))
    blob = b"".join(encoded_strings)
    source_hash = hashlib.sha256(canonical_json(tree)).digest()

    return b"".join((
        HEADER.pack(MAGIC, VERSION, 0, source_hash, len(strings), len(nodes), len(edges), len(blob)),
        struct.pack(f"<{len(offsets)}I", *offsets),
        blob,
        b"".join(NODE.pack(*node) for node in nodes),
        b"".join(EDGE.pack(*edge) for edge in edges if edge is not None),
    ))


def inspect(data: bytes) -> dict[str, int | str]:
    if len(data) < HEADER.size:
        raise ValueError("truncated FVL1 header")
    magic, version, flags, source_hash, string_count, node_count, edge_count, blob_size = HEADER.unpack_from(data)
    if magic != MAGIC or version != VERSION or flags != 0:
        raise ValueError("unsupported FVL1 table")
    offsets_size = (string_count + 1) * 4
    expected_size = HEADER.size + offsets_size + blob_size + node_count * NODE.size + edge_count * EDGE.size
    if len(data) != expected_size:
        raise ValueError(f"invalid FVL1 length: expected {expected_size}, got {len(data)}")
    offsets = struct.unpack_from(f"<{string_count + 1}I", data, HEADER.size)
    if offsets[0] != 0 or offsets[-1] != blob_size or any(a > b for a, b in zip(offsets, offsets[1:])):
        raise ValueError("invalid FVL1 string offsets")
    return {
        "version": version,
        "strings": string_count,
        "nodes": node_count,
        "edges": edge_count,
        "string_bytes": blob_size,
        "source_sha256": source_hash.hex(),
        "file_bytes": len(data),
    }


def command_build(source: Path, destination: Path) -> None:
    tree = json.loads(source.read_text(encoding="utf-8"))
    destination.write_bytes(encode(tree))
    print(json.dumps(inspect(destination.read_bytes()), indent=2))


def command_selftest() -> None:
    tree = {"items": {"cow": {"name": "Cow", "plural": "Cows"}}, "ui": {"close": "Close"}}
    first = encode(tree)
    second = encode(json.loads(canonical_json(tree)))
    if first != second:
        raise AssertionError("FVL1 output is not deterministic")
    result = inspect(first)
    if result["strings"] != 9 or result["nodes"] != 7:
        raise AssertionError(f"unexpected FVL1 counts: {result}")
    print("FVL1 self-test passed")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    build = commands.add_parser("build")
    build.add_argument("source", type=Path)
    build.add_argument("destination", type=Path)
    inspect_command = commands.add_parser("inspect")
    inspect_command.add_argument("source", type=Path)
    commands.add_parser("selftest")
    args = parser.parse_args()

    if args.command == "build":
        command_build(args.source, args.destination)
    elif args.command == "inspect":
        print(json.dumps(inspect(args.source.read_bytes()), indent=2))
    else:
        command_selftest()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"error: {error}", file=sys.stderr)
        raise SystemExit(1)
