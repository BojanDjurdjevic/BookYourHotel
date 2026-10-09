@if($supplier->is_demo_sandbox)
    <p class="text-sm text-blue-400 mt-2">Recruiter sandbox · shared temporary account · private hotels</p>
@elseif(\App\Support\EmailAddress::isDemo($supplier->email))
    <p class="text-sm text-gray-400 mt-2">Fictional demo account</p>
@endif
