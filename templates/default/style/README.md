# Custom Theme Extensions

This directory contains example files for creating custom theme extensions that survive Poweradmin updates.

## Quick Start

1. **Copy the example files:**
   ```bash
   cp custom_light.css.example custom_light.css
   cp custom_dark.css.example custom_dark.css
   ```

2. **Edit the CSS files** to match your branding/preferences

3. **Refresh your browser** - custom styles are automatically loaded!

## How It Works

- **`custom_light.css`** - Loaded automatically when using Light theme
- **`custom_dark.css`** - Loaded automatically when using Dark theme
- Custom stylesheets override base theme styles using CSS cascade
- Files are only loaded if they exist (no errors if missing)

## Benefits

✅ **Update-safe** - Your customizations survive all Poweradmin updates  
✅ **Automatic loading** - No configuration needed  
✅ **Theme switching** - Works with existing light/dark theme switcher  
✅ **CSS cascade** - Your styles naturally override base themes  

## Tips

- Use `!important` to ensure your styles override base theme styles
- Remove example styles you don't need
- Test with both light and dark themes if you use theme switching
- Remove the theme indicator styles for production use

## File Structure

```
templates/default/style/
├── light.css                    # Base light theme (don't modify)
├── dark.css                     # Base dark theme (don't modify)
├── custom_light.css.example     # Light theme customization examples
├── custom_dark.css.example      # Dark theme customization examples
├── custom_light.css             # Your light theme customizations (create this)
└── custom_dark.css              # Your dark theme customizations (create this)
```