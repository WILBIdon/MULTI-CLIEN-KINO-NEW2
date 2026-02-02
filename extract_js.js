
const fs = require('fs');

try {
    const content = fs.readFileSync('index.php', 'utf8');
    const startMarker = '<script>';
    const endMarker = '</script>';

    // Find the LAST script block or Iterate?
    // index.php likely has one main script block at the end.
    // Based on previous reads, it starts around line 601.

    const parts = content.split('<script>');
    if (parts.length < 2) {
        console.log("No script tag found");
        process.exit(1);
    }

    // The main script is likely the last one or the one with specific vars
    const lastPart = parts[parts.length - 1];
    const scriptContent = lastPart.split('</script>')[0];

    // Write to file for checking
    fs.writeFileSync('temp_check.js', scriptContent);
    console.log("Extracted JS to temp_check.js");

} catch (e) {
    console.error(e);
}
