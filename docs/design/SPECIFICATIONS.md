# DESIGN SPECIFICATIONS

## Global Interface Description

The interface is approximately 1200px wide, centered on screen for an optimal user experience.

The interface is clean, minimalist, and elegant, with a light soothing color palette.

The interface contains no route graph or illustration, and no user avatar panel.

The interface should have a white background to maintain a clean and minimalist design.

## Field Descriptions

### Magic Link

The "magic link" field is a text input that allows the user to enter a Komoot link. This field is prominently highlighted to clearly indicate its importance in the trip planning process. It is placed at the top of the interface, centered, is larger than the other fields (150%), spans the full width of the interface to immediately draw the user's attention, and with a #EBF5F6 background color without border.

The "magic link" input fields follow a clean design with circle-rounded corners and a thin border, and contains a thick padding.

The "magic link" field has no submit button, as the user's input is automatically interpreted. On submission, a loading indicator (spinner) is displayed inside or next to the field to inform the user that data is being processed. In case of an error (invalid link, data extraction failure, etc.), a clear and concise error message is displayed below the field to inform the user of the problem.

The total distance and elevation gain (data extracted from Komoot) are displayed below the "magic link" field as an information visual with a colored icon to differentiate them (e.g., a bike icon for distance, a mountain icon for elevation), and a concise description (e.g., "Total distance: 250km", "Total elevation: 3000m").

### Title

The title has a default auto-generated value suggesting an original title based on the name of a notable feminist figure (e.g., "Annie Londonderry", "Evelyne Carrer", etc.). Its size is smaller than the "magic link" field to indicate lower importance, but bigger than other texts (120%); it occupies 50% of the interface width and is placed on the left. It has no visible input field styling, but the user can still edit its value by clicking on it and typing. The field should appear as normal text, with no background or border. A #9DA5A7 colored pencil icon located to the right of the field indicates (on hover) that the field is editable.

The title is placed just below the "magic link" field and the distance/elevation display.

### Locations

The departure and arrival location fields allow the user to enter the start and end points of their trip. A vertical line on the left indicates that those fields work together. If the trip is a loop, the arrival location is slightly grayed out to indicate it matches the departure location, but remains editable, and a visual indicator indicates it's a loop.

Typing in a location field offers place suggestions powered by a search engine.

These fields are normal size (100%), smaller than the "magic link" and title fields to indicate lower importance. Each occupies 50% of the interface width and is placed below the title, forming a 50%-wide column consisting of the title and locations across 3 distinct rows. A high spacing separates the title from the locations to visually differentiate these elements as 2 blocks. A #9DA5A7 colored pencil icon located to the right of each field indicates (on hover) that the field is editable. The locations pencil icons must align with the title pencil icon vertically. The locations fields have no visible input field styling, but the user can still edit their values by clicking on them and typing. The fields should appear as normal text, with no background nor border.

### Dates

A widget allows the user to select the trip start and end dates, with a calendar appearance to facilitate date selection. This widget supports both compact and full display modes.

In compact mode, only the week containing the start date is shown (with day-of-week labels).

In full mode, the calendar displays complete weeks of the month, with day-of-week labels and corresponding dates, and intuitive navigation to move between months. Dates from the previous and next months are slightly faded to distinguish them from the current month.

The month name and year are displayed in bold at the top of the widget with the same size as the title (120%), with day-of-week labels in bold below with a normal size (100%), and corresponding dates in normal size too aligned underneath.

An arrow to the right of the month and year toggles between compact and full calendar views. The arrow points downward in compact mode to expand to full view, and upward in full mode to collapse back to compact.

The start date and the end date are highlighted with a #3AA5B9 circle background color, a white thick border and a white text color. The dates in between have a normal style.

Selected dates are highlighted altogether with a circle-rounded bounding rectangle with a #3AA5B9 thin border.

The bounding rectangle spans from the start date to the end date, even if they fall on different weeks, using a continuous line for consecutive days and a dashed line for non-consecutive days.

The date selection widget is positioned at the same level as the title and location fields, to their right, forming a 50%-wide column. It does not have any background nor border.

### Weather

An indicator displays the forecasted weather for the trip's dates and locations, with a colored icon and a concise description (e.g., "sunny", "rainy", etc.).

This indicator is placed below the "title" and "locations" fields, in the same column, with a high margin on top to visually separate it from the previous elements. It has a smaller size than the previous fields (80%) and a light-gray text color to indicate it as a notice.

### Timeline

The trip stages are displayed along a vertical timeline: this is the core of the interface.

The timeline is divided into segments corresponding to each day of the trip, with visual markers. Each segment begins with a visual marker (a small empty circle with a #3AA5B9 big outline), followed by a #3AA5B9 vertical line disconnected from this marker and indicating progression to the next date marker.

Stages are positioned to the right of the timeline, vertically aligned with the corresponding date segments.

### Stages

Each stage is represented to the right of the timeline, correctly positioned vertically according to its date.

Each stage follows the timeline and displays in 3 rows:

1. the departure location in bold with a #9DA5A7 colored pencil icon to the right indicating (on hover) that this field is editable, occupying 50% of the width, with a right arrow icon to the right, and then followed by the arrival location in bold with the same design. Both locations with the arrow in between indicate the direction (from a location to another)
2. the metadata: stage distance, elevation gain and forecasted weather, each with a colored icon (and concise description for forecasted weather, e.g., "sunny", "rainy", etc.), in a smaller and light-gray text color to indicate them as a notice
3. any applicable alerts

Upon entering the arrival location, the stage's distance and elevation are automatically detected and displayed between the departure and arrival locations, with an icon to differentiate them (e.g., a bike icon for distance, a mountain icon for elevation). The space between departure and arrival always reserves room for the metadata display (distance, elevation gain, etc.), even if the metadata have not yet been detected (e.g., before the arrival location is entered).

Typing in a location field offers place suggestions powered by a search engine.

A stage may display the following alerts:

- if the stage date falls on a public holiday (shops may be closed)
- if the stage route is too long (distance > 80km)
- if the stage route is too difficult (elevation > 1200m)
- if no SNCF train station is detected within 10km of the stage route
- if no cemetery is detected within 10km of the stage route (water point)
- if a moveable bridge is detected on the stage route
- if the route passes near a Greenway (Voie Verte) or EuroVelo route (recommending these alternatives for safety and comfort)
- if the cumulative distance over 3 consecutive stages exceeds 230km (recommending a rest day to prevent fatigue and injury)

A stage is designed as a card with a thin clear border and no background, and a thin clear shadow to detach it from the main background. The card has rounded corners for a softer appearance. The stage card is sized to fit its content, with a maximum width of 80% of the interface width to maintain readability and visual balance. The stage card contains the stage followed by the accommodations panel, separated by a high margin to visually differentiate these elements as two distinct blocks. The "+ Add stage" button between stages is the same width as the stage card to maintain visual consistency but separated from the card, with a big margin.

Between each stage, a #9DA5A7 dashed-border "+ Add stage" button without background and with a light-gray text color allows the user to insert an intermediate stage between two stages, with empty location fields displaying their #9DA5A7 colored pencil icons to invite input. This insert panel is the same size (width and height) as the stage cards.

Each stage has a close (×) button to remove it from the timeline.

When a stage is added or removed, the other stages are automatically reorganized to accommodate the change, a dashed button is automatically added between each stage, dates are adjusted accordingly, and an alert on the "magic link" field indicates that the route has been modified. Alerts are updated to reflect the changes in the itinerary (particularly with respect to dates). The trip end date is automatically adjusted if necessary to accommodate the added intermediate stage.

No "+ Add stage" button appears before the first stage nor after the last stage.

### Accommodations

Between each stage, a panel lists available accommodations near the arrival location of that stage.

These accommodations appear before the dashed-border "+ Add stage" button, between two stages, as they concern the nights between stages. It appears with a slightly light-gray background to distinguish it from the stage.

No accommodation panel appears before the first stage or after the last stage, since there is no overnight stay before the trip begins or after it ends.

Each accommodation displays the accommodation name, a link, and its price range. A #9DA5A7 colored pencil icon to the right of each of these fields indicates (on hover) that the field is editable. The name is displayed in bold to distinguish it from the rest of the text. The link is displayed below, smaller, with a link icon for visual differentiation. The price range is displayed below that, smaller, with a currency icon for visual differentiation.

Within this accommodations panel, a #9DA5A7 dashed-border "+ Add accommodation" button without background and with a light-gray text color allows the user to add an accommodation. When clicked, an empty accommodation entry is added for the user to fill in. Within this accommodations panel, accommodations are listed vertically and separated by a light thin line to visually differentiate them as distinct entries.

Each accommodation has a close (×) button to remove it from the accommodations panel.

The "+ Add accommodation" button is always located below any already-added accommodations, so that accommodations are grouped at the top of the panel and the button remains accessible at the bottom.

### Alerts

Alerts are displayed in a succinct, clear, and visible manner, with a background color indicating their severity (e.g., red for alerts, orange for warnings, blue for information, etc.), and a no border. Each alert is displayed vertically separated from the others with a margin to visually differentiate them as distinct alerts.

Users are encouraged to take these alerts into account when planning their trip.

### PDF Export

A clearly identified button allows the user to export their itinerary as a PDF.

This button is located after the timeline, totally detached from it, horizontally centered on the interface, for easy access once the user has finished planning their trip. The button is high-sized (100%), with circle-rounded corners and a #3AA5B9 background color and white text, indicating its function (e.g., "Export as PDF"). A download icon may be added next to the text to visually reinforce the button's purpose.
