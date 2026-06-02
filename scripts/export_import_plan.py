#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Export Kaman inventory import as JSON plan (no API calls)."""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent))

# Reuse loaders from sibling module
from kaman_inventory_import import (
    FOOD_XLSX,
    load_csv_rows,
    load_food_cost_links,
    load_raw_material_names,
    norm_name,
)

ROOT = Path(__file__).resolve().parents[1]


def step(
    steps: list[dict[str, Any]],
    *,
    phase: str,
    title: str,
    method: str,
    path: str,
    body: dict | None = None,
    save_as: str | None = None,
    note: str | None = None,
) -> None:
    entry: dict[str, Any] = {
        "id": f"step-{len(steps) + 1:05d}",
        "phase": phase,
        "title": title,
        "http": {
            "method": method.upper(),
            "path": path if path.startswith("/") else f"/{path}",
            "body": body or {},
        },
        "status": "pending",
    }
    if save_as:
        entry["save_as"] = save_as
    if note:
        entry["note"] = note
    steps.append(entry)


def build_plan(
    *,
    limit: int,
    skip_stock: bool,
    skip_links: bool,
    include_ingredients: bool,
    include_recipe_links: bool,
    suppliers_map: dict[str, str],
) -> dict[str, Any]:
    steps: list[dict[str, Any]] = []
    csv_rows = load_csv_rows()
    raw_materials = load_raw_material_names() if include_ingredients else []
    recipe_links = load_food_cost_links() if include_recipe_links and not skip_links else []

    step(
        steps,
        phase="login",
        title="Login (manager API)",
        method="POST",
        path="/api/manager/login",
        body={"email": "{{email}}", "password": "{{password}}"},
        note="Token applied automatically to following requests when executing.",
    )

    step(
        steps,
        phase="fetch_inventory",
        title="List inventory items (GET)",
        method="GET",
        path="/api/manager/inventory-items",
        note="Prefetches existing inventory by name/SKU before creates.",
    )

    count = limit if limit > 0 else len(csv_rows)
    for row in csv_rows[:count]:
        key = norm_name(row.name)
        step(
            steps,
            phase="create_inventory",
            title=f"Create inventory: {row.name}",
            method="POST",
            path="/api/manager/inventory-items",
            body={
                "name_ar": row.name,
                "name_en": row.name,
                "name_he": row.name,
                "sku": row.sku,
                "unit": row.unit,
                "minimum_quantity": 1,
                "track_inventory": True,
                "depreciation_rate": 0,
            },
            save_as=f"inventory:{key}",
        )
        if not skip_stock and row.supplier:
            sid = suppliers_map.get(norm_name(row.supplier))
            if sid:
                step(
                    steps,
                    phase="receive_stock",
                    title=f"Receive stock: {row.name} ({row.supplier})",
                    method="POST",
                    path=f"/api/manager/inventory-items/@inventory:{key}/receive-stock",
                    body={
                        "supplier_id": sid,
                        "quantity": round(row.pack_qty, 4),
                        "price_per_unit": round(row.price, 4),
                        "received_date": "{{today}}",
                        "notes": row.supplier[:250],
                    },
                )
            else:
                step(
                    steps,
                    phase="receive_stock",
                    title=f"Receive stock SKIPPED (no supplier UUID): {row.name}",
                    method="POST",
                    path=f"/api/manager/inventory-items/@inventory:{key}/receive-stock",
                    body={
                        "supplier_id": "@supplier:" + norm_name(row.supplier),
                        "quantity": round(row.pack_qty, 4),
                        "price_per_unit": round(row.price, 4),
                        "notes": row.supplier[:250],
                    },
                    note=f"Map supplier «{row.supplier}» to a UUID in suppliers map.",
                )

    if include_ingredients and raw_materials:
        step(
            steps,
            phase="create_ingredient_category",
            title="Ensure ingredients category (raw materials)",
            method="POST",
            path="/api/manager/ingredients-categories",
            body={
                "name_ar": "مواد خام",
                "name_en": "Raw materials",
                "name_he": "חומרי גלם",
            },
            save_as="ingredient_category:raw",
            note="Skipped at execute time if category already exists.",
        )
        for name, qty in raw_materials:
            ikey = norm_name(name)
            step(
                steps,
                phase="create_ingredient",
                title=f"Create ingredient: {name}",
                method="POST",
                path="/api/manager/ingredients",
                body={
                    "name_ar": name,
                    "name_en": name,
                    "name_he": name,
                    "price": 0,
                    "category_id": "@ingredient_category:raw",
                },
                save_as=f"ingredient:{ikey}",
            )
            step(
                steps,
                phase="link_ingredient",
                title=f"Link inventory -> ingredient: {name}",
                method="POST",
                path=f"/api/manager/inventory-items/@inventory:{ikey}/link-ingredient",
                body={
                    "ingredient_id": f"@ingredient:{ikey}",
                    "quantity_consumed": round(qty if qty > 0 else 0.01, 6),
                },
                note="Requires matching inventory row from CSV (same Hebrew name).",
            )

    if include_recipe_links and recipe_links:
        for link in recipe_links:
            dish_key = norm_name(link["dish"])
            comp_key = norm_name(link["component"])
            qty = round(float(link["qty"]), 6)
            step(
                steps,
                phase="link_item",
                title=f"Link inventory -> menu item: {link['component']} -> {link['dish']}",
                method="POST",
                path=f"/api/manager/inventory-items/@inventory:{comp_key}/link-item",
                body={
                    "item_id": f"@item:{dish_key}",
                    "quantity_consumed": qty,
                },
                note="Resolves menu item by dish name from Kaman at execute time.",
            )

    phases: dict[str, int] = {}
    for s in steps:
        phases[s["phase"]] = phases.get(s["phase"], 0) + 1

    return {
        "summary": {
            "total_steps": len(steps),
            "csv_rows_in_plan": count,
            "csv_rows_total": len(csv_rows),
            "raw_material_rows": len(raw_materials),
            "recipe_links": len(recipe_links),
            "phases": phases,
            "food_cost_file": str(FOOD_XLSX.name),
        },
        "steps": steps,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--limit", type=int, default=50)
    parser.add_argument("--skip-stock", action="store_true")
    parser.add_argument("--skip-links", action="store_true")
    parser.add_argument("--no-ingredients", action="store_true")
    parser.add_argument("--no-recipe-links", action="store_true")
    parser.add_argument("--suppliers-map", type=Path, default=None)
    args = parser.parse_args()

    supplier_map: dict[str, str] = {}
    if args.suppliers_map and args.suppliers_map.exists():
        supplier_map = {
            norm_name(k): v
            for k, v in json.loads(args.suppliers_map.read_text(encoding="utf-8")).items()
        }

    plan = build_plan(
        limit=args.limit,
        skip_stock=args.skip_stock,
        skip_links=args.skip_links,
        include_ingredients=not args.no_ingredients,
        include_recipe_links=not args.no_recipe_links and not args.skip_links,
        suppliers_map=supplier_map,
    )

    out = json.dumps(plan, ensure_ascii=False)
    if args.json:
        sys.stdout.buffer.write(out.encode("utf-8"))
    else:
        print(out)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
