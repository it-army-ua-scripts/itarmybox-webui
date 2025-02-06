<?php

// Define the data to be passed to the template
$data = array(
    'name' => 'John Doe',
    'age' => 30
);

// Load the template file
$template = file_get_contents('templates/index.html');

// Replace placeholders with actual data
foreach ($data as $key => $value) {
    $template = str_replace('{{ ' . $key . ' }}', $value, $template);
}

// Output the rendered HTML
echo $template;

?>
