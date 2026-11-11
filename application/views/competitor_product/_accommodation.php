<?php
// Shared by the screen and PDF. Use the source's day and location only when
// the record contains both explicitly. Older free-text records still render.
?>
<table class="ca-accommodation">
    <thead><tr>
        <th scope="col"><?php echo htmlspecialchars($lbl('night_day', 'Night / Day')); ?></th>
        <th scope="col"><?php echo htmlspecialchars($lbl('location', 'Location')); ?></th>
        <th scope="col"><?php echo htmlspecialchars($lbl('hotels', 'Accommodation')); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($hotel_entries as $entry) {
        if (preg_match('/^((?:Days?|Nights?)\s+\d+(?:\s*[-–]\s*\d+)?)\s*[—–]\s*([^:]+):\s*(.+)$/isu', $entry, $stay)) {
            $days = $stay[1];
            $location = trim($stay[2]);
            $hotel = trim($stay[3]);
        } else {
            $days = $location = '';
            $hotel = $entry;
        }
        ?>
        <tr>
            <td><?php echo htmlspecialchars($days); ?></td>
            <td><?php echo htmlspecialchars($location); ?></td>
            <td><?php echo htmlspecialchars($hotel); ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
