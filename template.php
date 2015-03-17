<?php
function formatDate($date){
	$start_date = strtotime( $date );
	return date( 'Y-m-d H:i:s', $start_date );
}
function formatElapsedTime($total_seconds)
{
	$d1 = new DateTime();
	$d2 = new DateTime();
	$d2->add(new DateInterval('PT'.$total_seconds.'S'));
	$elapsed_time = $d2->diff($d1);
	return $elapsed_time->format('%H:%I:%S');
}

function formatDistance($distance)
{
	return round($distance * 0.000621371,2);	
}

function formatElevation($elevation)
{
	return round($elevation * 3.28084,2);
}

$data = get_post_custom_values('raw_import_data', $post->ID);
$data = utf8_encode($data[0]);
$data = str_replace("\\","\\\\",$data); 
$data = json_decode($data);
?>
<div class='strava'>
	<div class='container'>
		<div class='header'>
			<a href="https://www.strava.com/athletes/<?php echo $data->athlete->id;?>" class="branding">Strava</a>
			<div class='details'>
				<h1>
					<?php echo $data->type;?>
					<a href="https://www.strava.com/activities/<?php echo $data->id;?>" target="_parent" title="<?php echo $data->name;?>"><?php echo $data->name;?></a>
				</h1>
				<div class='timestamp'>
					<time><?php echo formatDate($data->start_date);?></time>
				</div>
				<ul class='stats'>
					<li>
						<div class='distance sprite'>Distance</div>
						<strong><?php echo formatDistance($data->distance);?> mi</strong>
					</li>
					<li>
						<div class='time sprite'>Time</div>
						<strong><?php echo formatElapsedTime($data->elapsed_time);?></strong>
					</li>
					<li>
						<div class='elevation sprite'>Elevation</div>
						<strong><?php echo formatElevation($data->total_elevation_gain);?> ft</strong>
					</li>
				</ul>
			</div>
			<div class='clear'></div>
		</div>
		<div class='content'>
			<div class='mapContainer'>
				<a href="https://www.strava.com/activities/<?php echo $data->id;?>" target="_parent">
					<img class="map" src="https://maps.google.com/maps/api/staticmap?maptype=terrain&amp;path=color%3A0xFF0000BF%7Cweight%3A2%7Cenc%3A<?php echo $data->map->summary_polyline;?>&amp;scale=1&amp;sensor=false&amp;size=566x228" title="<?php echo $data->name;?>" />
				</a>
			</div>
		</div>
	</div>
</div>