<?php

declare(strict_types=1);

/**
 * Keep the legacy XML item path in sync with the optimized item AMF.
 *
 * Current clients normally use items_opt.amf, but older experiment
 * assignments and the AMF retry path still load items.xml.gz. Without these
 * records Flash can create the world-object shadow/label while having no
 * renderable item definition for the Spooky UGC buildings.
 */

$basePath = __DIR__ . '/../public/farmville/xml/gz/v855038';
$files = [
    ['path' => $basePath . '/items.xml', 'compressed' => false],
    ['path' => $basePath . '/items1.xml', 'compressed' => false],
    ['path' => $basePath . '/items.xml.gz', 'compressed' => true],
    ['path' => $basePath . '/items.xml.gz1', 'compressed' => true],
];

$definitions = [
    'xhw_ugc_deco_workshop' => <<<'XML'
		<item name="xhw_ugc_deco_workshop" className="FeatureBuilding" type="building" code="7F6" buyable="true" giftable="false" placeable="true" experimentGate="fv_xhw_ugc_buildings" experimentVariant="1,2,3,4" sizeX="4" sizeY="4" limit="1" limitType="name" assetKey="ugc_deco_workshop" overrideExportName="xhwugcworkshop">
			<buildingName>UGCDecoWorkshop</buildingName>
			<dialogName>FeatureBuildingGeneric</dialogName>
			<image name="built_0" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_bldg_ugc_workshop.swf" loadClass="1"/>
			<image name="icon" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_bldg_ugc_workshop_icon.png"/>
			<image name="feed" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_bldg_ugc_workshop_icon_200.png"/>
			<features>
				<feature name="contextMenu" className="ContextMenuFManager">
					<menuItem name="Dialogs:FeatureBuildingLookInside" experiment="fv_xhw_ugc_buildings" action="lookInside"/>
				</feature>
				<feature name="arrowIndicator" className="UGCDecoWorkshopIndicatorFManager">
					<state name="default"/>
				</feature>
			</features>
		</item>
	XML,
    'xhw_ugc_deco_spookyshack' => <<<'XML'
		<item name="xhw_ugc_deco_spookyshack" className="UGCDecoration" type="decoration" code="7DV" buyable="true" giftable="false" placeable="true" experimentGate="fv_xhw_ugc_buildings" sizeX="5" sizeY="5" cost="100000" assetKey="ugc_deco_spookyshack" overrideExportName="xhw_ugc_deco_spookyshack">
			<image name="base" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_ugc_deco_spookyshack_base.swf" loadClass="mc"/>
			<image name="icon" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_ugc_deco_spookyshack_blueprint_icon.png"/>
			<image name="feed" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_ugc_deco_spookyshack_blueprint_icon_200.png"/>
			<materialPrice>
				<material name="xhw_ugc_deco_darkwood" quantity="9"/>
				<material name="xhw_ugc_deco_slime" quantity="8"/>
				<material name="xhw_ugc_deco_cobweb" quantity="0"/>
				<material name="xhw_ugc_deco_decorator" quantity="8"/>
			</materialPrice>
		</item>
	XML,
    'xhw_ugc_deco_pumpkinhouse' => <<<'XML'
		<item name="xhw_ugc_deco_pumpkinhouse" className="UGCDecoration" type="decoration" code="7FC" buyable="true" giftable="false" placeable="true" experimentGate="fv_xhw_ugc_buildings" sizeX="6" sizeY="6" cost="100000" assetKey="ugc_deco_pumpkinhouse" overrideExportName="xhw_ugc_deco_pumpkinhouse">
			<image name="base" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_ugc_deco_pumpkinhouse_base.swf" loadClass="mc"/>
			<image name="icon" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_ugc_deco_pumpkinhouse_blueprint_icon.png"/>
			<image name="feed" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_ugc_deco_pumpkinhouse_blueprint_icon_200.png"/>
			<materialPrice>
				<material name="xhw_ugc_deco_darkwood" quantity="9"/>
				<material name="xhw_ugc_deco_slime" quantity="11"/>
				<material name="xhw_ugc_deco_cobweb" quantity="0"/>
				<material name="xhw_ugc_deco_decorator" quantity="15"/>
			</materialPrice>
		</item>
	XML,
    'xhw_ugc_deco_hauntedhouse' => <<<'XML'
		<item name="xhw_ugc_deco_hauntedhouse" className="UGCDecoration" type="decoration" code="8Np" buyable="true" giftable="false" placeable="true" experimentGate="fv_xhw_ugc_buildings" sizeX="8" sizeY="10" cost="100000" assetKey="ugc_deco_hauntedhouse" overrideExportName="xhw_ugc_deco_hauntedhouse">
			<image name="base" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_ugc_deco_hauntedhouse_base.swf" loadClass="mc"/>
			<image name="icon" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_ugc_deco_hauntedhouse_blueprint_icon.png"/>
			<image name="feed" url="assets/buildings/expansions/xhw/xhw_ugc_building/xhw_ugc_deco_hauntedhouse_blueprint_icon_200.png"/>
			<materialPrice>
				<material name="xhw_ugc_deco_darkwood" quantity="11"/>
				<material name="xhw_ugc_deco_slime" quantity="12"/>
				<material name="xhw_ugc_deco_cobweb" quantity="10"/>
				<material name="xhw_ugc_deco_decorator" quantity="22"/>
			</materialPrice>
		</item>
	XML,
    'spookytree2' => <<<'XML'
		<item name="spookytree2" type="tree" subtype="fruit" code="8nP" market="cash" buyable="true" giftable="true" placeable="true" present="true" experimentGate="fv_hallow" sizeX="1" sizeY="1" imageScale="3">
			<requiredLevel>1</requiredLevel>
			<cost>5000</cost>
			<cash>8</cash>
			<growTime>2</growTime>
			<coinYield>150</coinYield>
			<masteryYield>1</masteryYield>
			<image name="bare" url="assets/trees/tree_spookytree.swf" loadClass="1"/>
			<image name="mature" url="assets/trees/tree_spookytree.swf" loadClass="1"/>
			<image name="icon" url="assets/trees/tree_spookytree_icon.png"/>
			<image name="feed" url="assets/trees/tree_spookytree_icon_200.png"/>
		</item>
	XML,
    'xtr_spookybraintree' => <<<'XML'
		<item name="xtr_spookybraintree" type="tree" subtype="fruit" code="0ljd" market="cash" buyable="true" giftable="true" placeable="true" experimentGate="fv_xtr_master" sizeX="1" sizeY="1" imageScale="3">
			<requiredLevel>1</requiredLevel>
			<cost>1500</cost>
			<cash>8</cash>
			<growTime>2</growTime>
			<coinYield>150</coinYield>
			<masteryYield>1</masteryYield>
			<image name="bare" url="assets/trees/xtr_spookybraintree.swf" loadClass="1"/>
			<image name="mature" url="assets/trees/xtr_spookybraintree.swf" loadClass="1"/>
			<image name="icon" url="assets/trees/xtr_spookybraintree_icon.png"/>
			<image name="feed" url="assets/trees/xtr_spookybraintree_icon_200.png"/>
		</item>
	XML,
];

$requiredXml = static function (string $xml, string $name): bool {
    return preg_match('/<item\b[^>]*\bname="' . preg_quote($name, '/') . '"/', $xml) === 1;
};

$totalPatched = 0;
foreach ($files as $file) {
    if (!is_file($file['path'])) {
        throw new RuntimeException("Item catalog file not found: {$file['path']}");
    }

    $contents = file_get_contents($file['path']);
    if ($contents === false) {
        throw new RuntimeException("Could not read item catalog: {$file['path']}");
    }

    $xml = $file['compressed'] ? @gzuncompress($contents) : $contents;
    if ($xml === false) {
        throw new RuntimeException("Could not decompress item catalog: {$file['path']}");
    }

    $missing = [];
    foreach ($definitions as $name => $definition) {
        if (!$requiredXml($xml, $name)) {
            $missing[$name] = $definition;
        }
    }

    if ($missing !== []) {
        $insertion = "\n" . implode("\n", $missing) . "\n";
        $patched = preg_replace('/\s*<\/items>/', $insertion . "\t</items>", $xml, 1, $replacements);
        if ($patched === null || $replacements !== 1) {
            throw new RuntimeException("Could not append UGC definitions to {$file['path']}");
        }
        $xml = $patched;
        $totalPatched += count($missing);
    }

    $output = $file['compressed'] ? gzcompress($xml, 9) : $xml;
    if ($output === false || file_put_contents($file['path'], $output) === false) {
        throw new RuntimeException("Could not write item catalog: {$file['path']}");
    }
}

$optimizedPath = $basePath . '/items_opt.amf';
$optimized = is_file($optimizedPath) ? @gzuncompress((string) file_get_contents($optimizedPath)) : false;
if ($optimized === false) {
    throw new RuntimeException("Optimized item catalog is missing or invalid: {$optimizedPath}");
}

foreach (array_keys($definitions) as $name) {
    if (!str_contains($optimized, $name)) {
        throw new RuntimeException("Optimized item catalog is missing {$name}");
    }
}

fwrite(STDOUT, "Patched {$totalPatched} missing Spooky/UGC item definitions; optimized AMF verified.\n");
