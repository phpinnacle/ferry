<?php

return [
    'transform_input_type' => 'The ":transform" transform does not support its input type.',
    'primary_key_transforms_prohibited' => 'Transformations are prohibited on source primary-key fields.',
    'replica_schema' => [
        'format' => 'The field mapping must be a list of rows',
        'empty' => 'Add at least one field mapping',
        'source_required' => 'Select a source field in each row',
        'column_required' => 'Specify a column name in each row',
        'source_duplicate' => 'The source field ":field" is mapped more than once',
        'column_duplicate' => 'The column name ":column" is used more than once',
        'column_format' => 'The column name ":column" must start with a letter and contain only Latin letters, digits and underscores',
        'primary_key_required' => 'Map every source primary-key column: :columns',
        'primary_key_missing' => 'The source must declare a primary key',
    ],
];
