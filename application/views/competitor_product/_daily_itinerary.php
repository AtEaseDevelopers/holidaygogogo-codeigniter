<!-- Daily itinerary -->
<?php if($has($av('itinerary'))) { ?>
    <div class="mb-5">
        <h5 class="font-weight-bolder text-dark mb-3"><i class="la la-route mr-1" style="color:#6082B6;"></i><?php echo htmlspecialchars($lbl('daily_itinerary', 'Daily Itinerary')); ?></h5>
        <div style="border-left:2px solid #E4E6EF; padding-left:20px;">
            <?php foreach($av('itinerary') as $day) {
                $day = (array) $day;
                $d_label = trim((string) (isset($day['day']) ? $day['day'] : ''));
                $d_title = trim((string) (isset($day['title']) ? $day['title'] : ''));
                $d_desc  = trim((string) (isset($day['description']) ? $day['description'] : ''));
            ?>
                <div class="ca-section-card mb-3" style="position:relative;">
                    <span style="position:absolute; left:-27px; top:16px; width:12px; height:12px; border-radius:50%; background:#6082B6; border:2px solid #fff;"></span>
                    <div class="font-weight-bolder" style="font-size:14px; color:#6082B6;"><?php echo htmlspecialchars($d_label); ?><?php if($d_title !== '') { echo ' — <span style="color:#3F4254;">' . htmlspecialchars($d_title) . '</span>'; } ?></div>
                    <?php if($d_desc !== '') { ?><div class="text-dark-50 mt-1" style="font-size:13px; white-space:pre-line;"><?php echo htmlspecialchars($d_desc); ?></div><?php } ?>
                </div>
            <?php } ?>
        </div>
    </div>
<?php } ?>
