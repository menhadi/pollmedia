<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OfficeholderDirectory
{
    /** Activate reviewed evidence for a single-holder office; retain prior observations. */
    public function replace(int $officeId,int $personId,int $releaseId,string $verifiedAt,?string $effectiveFrom=null): int
    {
        if($effectiveFrom!==null && $effectiveFrom>substr($verifiedAt,0,10))throw new InvalidArgumentException('Future appointments require separate staging.');
        return DB::transaction(function () use($officeId,$personId,$releaseId,$verifiedAt,$effectiveFrom) {
            if(!DB::table('offices')->where('id',$officeId)->lockForUpdate()->first())throw new InvalidArgumentException('Unknown office.');
            if(!DB::table('people')->where('id',$personId)->exists() || !DB::table('source_releases')->where('id',$releaseId)->where('status','accepted')->exists())throw new InvalidArgumentException('Person or accepted evidence missing.');
            $active=DB::table('office_assignments')->where('office_id',$officeId)->whereNull('superseded_at')->get();
            foreach($active as $a) {
                if($a->person_id===$personId && $a->source_release_id===$releaseId)return $a->id;
                if(!in_array($a->status,['last_verified','confirmed']))throw new InvalidArgumentException('Acting or conflicting assignments require explicit review.');
                if($a->verified_at>$verifiedAt)throw new InvalidArgumentException('Cannot replace newer evidence with an older check.');
                if($effectiveFrom!==null && $a->effective_from!==null && $a->effective_from>$effectiveFrom)throw new InvalidArgumentException('Invalid tenure chronology.');
            }
            if(DB::table('office_assignments')->where(['office_id'=>$officeId,'person_id'=>$personId,'source_release_id'=>$releaseId])->exists())throw new InvalidArgumentException('Historical evidence cannot be reactivated.');
            foreach($active as $a) {
                $changes=['superseded_at'=>$verifiedAt];
                if($effectiveFrom!==null)$changes['effective_to']=$effectiveFrom;
                DB::table('office_assignments')->where('id',$a->id)->update($changes);
            }
            return DB::table('office_assignments')->insertGetId(['office_id'=>$officeId,'person_id'=>$personId,'source_release_id'=>$releaseId,'verified_at'=>$verifiedAt,'effective_from'=>$effectiveFrom,'status'=>'confirmed']);
        });
    }
}
