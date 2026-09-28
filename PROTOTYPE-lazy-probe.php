<?php
// PROTOTYPE, throwaway: see issue #121. Run: APP_ENV=prod APP_DEBUG=0 php PROTOTYPE-lazy-probe.php
// PROTOTYPE probe: boots the kernel, loads a Subscription, touches its lazy owner/category.
use App\Kernel; use App\Entity\Subscription; use Symfony\Component\Dotenv\Dotenv;
$root='/Volumes/Sources/php/paysubscriptions';
require $root.'/vendor/autoload.php';
(new Dotenv())->bootEnv($root.'/.env');
$k=new Kernel($_SERVER['APP_ENV'], (bool)$_SERVER['APP_DEBUG']); $k->boot();
$em=$k->getContainer()->get('doctrine')->getManager();
$c=$em->getConfiguration();
printf("env=%s native=%s\n", $_SERVER['APP_ENV'], var_export($c->isNativeLazyObjectsEnabled(),true));
$id=$em->getConnection()->fetchOne('SELECT id FROM subscription LIMIT 1');
$s=$em->find(Subscription::class,$id);
$r=new ReflectionClass(Subscription::class);
foreach ($r->getProperties() as $p) { $v=$p->getValue($s); if (is_object($v) && str_starts_with(get_class($v),'App\\Entity')) {
  $rc=new ReflectionClass($v); $lazyBefore=$rc->isUninitializedLazyObject($v);
  $idp=$rc->hasMethod('getId')? $v->getId():null; $lazyAfterId=$rc->isUninitializedLazyObject($v);
  $str=method_exists($v,'getName')?$v->getName():(method_exists($v,'getEmail')?$v->getEmail():'?'); $lazyAfter=$rc->isUninitializedLazyObject($v);
  $ser=unserialize(serialize($v));
  printf("%s -> class=%s lazyBefore=%s afterGetId=%s afterRead=%s value=%s roundtrip=%s\n",$p->getName(),get_class($v),json_encode($lazyBefore),json_encode($lazyAfterId),json_encode($lazyAfter),$str,get_class($ser)); } }
