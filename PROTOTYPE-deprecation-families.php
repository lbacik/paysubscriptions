<?php
// Aggregate "  Nx: message" lines across all categories, collapse class names in messages.
$sec=''; $agg=[];
foreach (file($argv[1]) as $l) {
  if (preg_match('/^(Remaining |Other |Legacy )?(\w+ )?deprecation notices \((\d+)\)/i',$l,$m)) { $sec=trim($l); echo $l; continue; }
  if (preg_match('/^  (\d+)x: (.*)$/',$l,$m)) { $msg=substr($m[2],0,130); $agg[$msg]=($agg[$msg]??0)+(int)$m[1]; }
}
arsort($agg); foreach($agg as $k=>$v) printf("%6d  %s\n",$v,$k);
