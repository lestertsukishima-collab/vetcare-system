@php
    $role = auth()->user()->role;
    $links = ['dashboard'=>'Overview','pets'=>$role === 'owner' ? 'My pets' : 'Patients','appointments'=>'Appointments','records'=>'Medical records','prescriptions'=>'Prescriptions','vaccinations'=>'Vaccinations'];
    if (in_array($role,['owner','admin'])) $links['invoices'] = 'Invoices';
    if ($role === 'admin') $links += ['owners'=>'Pet owners','veterinarians'=>'Veterinarians','services'=>'Services','inventory'=>'Medicine inventory','transactions'=>'Stock history','payments'=>'Payments','users'=>'Users','reports'=>'Reports','settings'=>'Clinic settings'];
@endphp
