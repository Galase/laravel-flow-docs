<?php

declare(strict_types=1);

namespace Galase\FlowDocs\Support\Diagram;

final class DatabaseDiagramBuilder
{
    public static function build(array $migrations, array $models, callable $modelTableName): array
    {
        $modelTables = [];
        foreach ($models as $model) {
            $modelTables[$modelTableName($model)][] = $model['fqcn'];
        }

        $tableNames = array_keys($migrations['tables']);
        foreach ($migrations['tables'] as $schema) {
            foreach ($schema['foreign_keys'] as $fk) {
                $tableNames[] = $fk['references_table'];
            }
        }
        $tableNames = array_values(array_unique($tableNames));
        sort($tableNames);

        $nodeWidth = 260;
        $xGap = 80;
        $yGap = 70;
        $nodeHeights = [];
        foreach ($tableNames as $table) {
            $schema = $migrations['tables'][$table] ?? ['columns' => [], 'foreign_keys' => []];
            $nodeHeights[$table] = 76 + (min(14, max(1, count($schema['columns']))) * 30);
        }
        $columns = self::balancedColumns($nodeHeights, $nodeWidth, $xGap, $yGap);
        $rowHeights = [];
        $nodes = [];

        foreach ($tableNames as $index => $table) {
            $schema = $migrations['tables'][$table] ?? ['columns' => [], 'foreign_keys' => []];
            $row = intdiv($index, $columns);
            $column = $index % $columns;
            $nodeHeight = $nodeHeights[$table];
            $rowHeights[$row] = max($rowHeights[$row] ?? 0, $nodeHeight);
            $y = 40;
            for ($i = 0; $i < $row; $i++) {
                $y += ($rowHeights[$i] ?? $nodeHeight) + $yGap;
            }
            $nodes[$table] = [
                'name' => $table,
                'x' => 40 + ($column * ($nodeWidth + $xGap)),
                'y' => $y,
                'width' => $nodeWidth,
                'height' => $nodeHeight,
                'columns' => $schema['columns'],
                'models' => $modelTables[$table] ?? [],
            ];
        }

        $edges = [];
        foreach ($migrations['tables'] as $table => $schema) {
            foreach ($schema['foreign_keys'] as $fk) {
                $edges[] = [
                    'from' => $table,
                    'to' => $fk['references_table'],
                    'from_column' => $fk['column'],
                    'to_column' => $fk['references_column'],
                    'type' => '1:N',
                ];
            }
        }

        $width = 1200;
        $height = 520;
        foreach ($nodes as $node) {
            $width = max($width, $node['x'] + $node['width'] + 60);
            $height = max($height, $node['y'] + $node['height'] + 60);
        }

        return ['nodes' => $nodes, 'edges' => $edges, 'width' => $width, 'height' => $height];
    }

    private static function balancedColumns(array $nodeHeights, int $nodeWidth, int $xGap, int $yGap): int
    {
        $count = count($nodeHeights);
        if ($count <= 1) {
            return 1;
        }

        $bestColumns = 1;
        $bestScore = PHP_INT_MAX;
        for ($columns = 1; $columns <= $count; $columns++) {
            $rows = array_chunk(array_values($nodeHeights), $columns);
            $width = ($columns * $nodeWidth) + (($columns - 1) * $xGap);
            $height = array_sum(array_map(fn ($row) => max($row), $rows)) + ((count($rows) - 1) * $yGap);
            $ratio = $height > 0 ? $width / $height : 1;
            $score = abs(log(max(0.1, $ratio))) + ($columns > 7 ? (($columns - 7) * 0.12) : 0);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestColumns = $columns;
            }
        }

        return $bestColumns;
    }
}
