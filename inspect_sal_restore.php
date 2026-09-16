<?php
require_once '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();

use App\Models\WorldObject;

$row = WorldObject::where('world_id', 1)->where('object_id', 63)->first();
$atPosition = $row ? WorldObject::where('world_id', 1)
    ->where('position_x', $row->position_x)
    ->where('position_y', $row->position_y)
    ->where('object_id', '!=', 63)
    ->get(['object_id', 'item_name', 'class_name', 'state', 'deleted']) : collect();

echo json_encode([
    'row' => $row ? [
        'object_id' => $row->object_id,
        'item_name' => $row->item_name,
        'class_name' => $row->class_name,
        'state' => $row->state,
        'deleted' => (bool) $row->deleted,
        'position' => [$row->position_x, $row->position_y, $row->position_z],
        'contents' => $row->contents,
        'components' => $row->components,
    ] : null,
    'at_position' => $atPosition,
], JSON_PRETTY_PRINT), "\\n";
