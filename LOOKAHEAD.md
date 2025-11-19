# Lookahead View

## Overview
A web-based lookahead view for managing construction programmes, displaying activities in a timeline grid similar to Excel lookahead sheets.

## Features
- **Fixed left columns**: Sequence, Activity, Subcontractor, Start, Duration, Finish
- **Scrollable timeline**: Days across the top grouped by month
- **Filters**: Block, Floor, Date range, Weeks
- **Color-coded bars**: Activities shown with contractor-specific colors
- **Status indicators**: Planned, In Progress, Complete, Delayed
- **Responsive design**: Works on desktop, laptop, and tablet
- **Weekend highlighting**: Different background for Sat/Sun

## Files
- `/lookahead.html` - Main lookahead view (production)
- `/lookahead-test.html` - Test version using mock data
- `/api/lookahead.php` - API endpoint for production data
- `/api/lookahead-mock.php` - Mock API for testing without database

## API Contract

### GET /api/lookahead.php

**Query Parameters:**
- `block` (string) - Block identifier (e.g., "Block 3" or "3")
- `floor` (string, optional) - Floor name (e.g., "Basement", "Ground", "1st", "2nd", "3rd")
- `from` (string) - Start date in YYYY-MM-DD format (defaults to today)
- `weeks` (integer) - Number of weeks to display (default: 4, min: 1, max: 52)

**Response:**
```json
{
  "block": "A",
  "floor": "1st",
  "from": "2025-11-19",
  "to": "2025-12-17",
  "weeks": 4,
  "generated": "2025-11-19T10:20:00+00:00",
  "timezone": "Europe/London",
  "days": [
    {
      "date": "2025-11-19",
      "day_of_week": "Wed",
      "day": 19,
      "month": "Nov",
      "month_num": 11,
      "year": 2025
    }
  ],
  "activities": [
    {
      "id": 1,
      "sequence": 1,
      "code": null,
      "block": "A",
      "floor": "1st",
      "area": null,
      "description": "BWH to structural walls",
      "subcontractor": "Panacea",
      "start_date": "2025-11-19",
      "end_date": "2025-11-23",
      "duration_days": 5,
      "status": "in_progress",
      "critical": false,
      "delayed": false,
      "colour": "#60a5fa"
    }
  ]
}
```

## Customization

### Colors and Fonts
Edit the `<style>` section in `lookahead.html`:
- **Background**: `background: #0f172a` (dark slate)
- **Primary text**: `color: #e5e7eb` (light gray)
- **Highlight color**: `background: #2563eb` (blue for buttons)
- **Contractor colors**: Defined in database `contractors` table

### Default Lookahead Window
In `lookahead.html`, change the default in the Weeks dropdown:
```html
<option value="4" selected>4 weeks</option>  <!-- Change this -->
```

### Block/Floor Options
Edit the Floor dropdown in `lookahead.html`:
```html
<select id="filter-floor">
  <option value="">All Floors</option>
  <option value="Basement">Basement</option>
  <!-- Add more options here -->
</select>
```

## Usage

### Production
1. Navigate to `/lookahead.html`
2. Enter Block identifier (e.g., "A" or "Block 3")
3. Optionally select a Floor
4. Choose start date and number of weeks
5. Click "Load"
6. Use "Prev Week" / "Next Week" to navigate

### Testing (without database)
1. Navigate to `/lookahead-test.html`
2. Uses mock data from `/api/lookahead-mock.php`
3. Same interface and functionality

## Browser Support
- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers (responsive design)

## Implementation Notes
- No build tools required
- Plain CSS (no frameworks)
- Vanilla JavaScript (no libraries)
- PHP 8.x backend
- Works on Plesk shared hosting
