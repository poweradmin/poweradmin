# Custom Header and Footer Override

Place your custom template files in this directory to override the default header and footer.

## Files

- `header.html` - Overrides the main application header
- `footer.html` - Overrides the main application footer

## Usage

1. Copy the default templates from the parent directory as a starting point:
   ```bash
   cp ../header.html header.html
   cp ../footer.html footer.html
   ```

2. Modify the copied files to match your requirements

3. The custom templates will automatically be used when they exist in this directory

## Notes

- Custom templates must be valid HTML
- Keep the same structure and variable placeholders as the originals
- Test your changes after modification