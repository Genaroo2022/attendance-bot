<?php
defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'Asistencia ORT';
$string['modulenameplural'] = 'Asistencias ORT';
$string['pluginname'] = 'Asistencia ORT';
$string['pluginadministration'] = 'Administración de Asistencia ORT';

// Configuration
$string['configuration'] = 'Configuración';
$string['camerarequired'] = 'Cámara requerida';
$string['camerarequired_desc'] = 'Los estudiantes deben tener la cámara encendida para ser marcados como presentes';
$string['useemailmatching'] = 'Usar coincidencia por correo';
$string['useemailmatching_desc'] = 'Coincidir participantes por correo electrónico en lugar de nombre';
$string['minpercentage'] = 'Porcentaje mínimo de asistencia';
$string['latetolerance'] = 'Tolerancia de tardanza (minutos)';

// Date/Time
$string['datetimerange'] = 'Rango de fecha y hora';
$string['startdate'] = 'Fecha de inicio';
$string['enddate'] = 'Fecha de fin';
$string['starttime'] = 'Hora de inicio';
$string['endtime'] = 'Hora de fin';

// Recordings
$string['recordingsbackup'] = 'Respaldo de grabaciones';
$string['backuprecordings'] = 'Respaldar grabaciones';
$string['backuprecordings_desc'] = 'Respaldar automáticamente las grabaciones de Zoom';
$string['deletefromsource'] = 'Eliminar desde el origen';
$string['deletefromsource_desc'] = 'Eliminar las grabaciones de Zoom después del respaldo';

// Settings
$string['zoomsettings'] = 'Configuración de la API de Zoom';
$string['zoomsettings_desc'] = 'Configurar las credenciales OAuth de Zoom';
$string['zoomclientid'] = 'Client ID de Zoom';
$string['zoomclientid_desc'] = 'Client ID OAuth obtenido de Zoom';
$string['zoomclientsecret'] = 'Client Secret de Zoom';
$string['zoomclientsecret_desc'] = 'Client Secret OAuth obtenido de Zoom';
$string['zoomaccountid'] = 'Account ID de Zoom';
$string['zoomaccountid_desc'] = 'Account ID de Zoom para Server-to-Server OAuth';

$string['backupsettings'] = 'Ajustes de respaldo';
$string['backupsettings_desc'] = 'Configurar las opciones de respaldo de grabaciones';
$string['localdirectory'] = 'Directorio local';
$string['localdirectory_desc'] = 'Ruta local donde se almacenarán las grabaciones';
$string['backuplimit'] = 'Límite de descargas en respaldo';
$string['backuplimit_desc'] = 'Número máximo de grabaciones a descargar por ejecución del task programado';
$string['maxfilesize'] = 'Tamaño máximo de archivo';
$string['maxfilesize_desc'] = 'Tamaño máximo permitido para respaldo en MB (las grabaciones que superen este tamaño serán omitidas)';

// Zoom configuration
$string['zoomconfig'] = 'Configuración de Zoom';
$string['zoomconfig_desc'] = 'Asistencia ORT usa las credenciales del plugin mod_zoom. Asegúrese de que mod_zoom esté instalado y configurado con sus credenciales OAuth Server-to-Server (Client ID, Client Secret, Account ID).';

// View page strings
$string['viewtitle'] = 'Instrucciones del Bot de Asistencia ORT';
$string['viewdescription1'] = 'Asistencia ORT es un plugin que se instala en un curso y funciona automáticamente en segundo plano mediante un cron. El cron está configurado para ejecutarse a la 1 AM y activar un programador.';
$string['viewdescription2'] = 'El programador se ejecuta cada 24 horas y, por cada curso donde el plugin esté instalado, inicia una tarea ad-hoc responsable de calcular la asistencia para quienes pertenecen al curso, para todos los grupos.';
$string['viewinstructions'] = '
    <p>Para el uso correcto, debe configurar los ajustes necesarios mediante el formulario. Para hacerlo, vaya a la pestaña de Configuraciones, en la sección "Configuración de Asistencia ORT":</p>
    <ul>
        <li><strong>Cámara requerida:</strong> Si está habilitado, los estudiantes deben tener la cámara encendida para ser marcados como presentes.</li>
        <li><strong>Porcentaje mínimo de asistencia:</strong> Valor porcentual de 0 a 100% que indica la asistencia mínima requerida. Este porcentaje se calcula sobre la duración de la reunión.</li>
        <li><strong>Tolerancia de tardanza:</strong> Valor de 0 a 60 minutos que indica cuántos minutos puede llegar una persona antes de ser considerada tardía. Si elige 0 minutos, la opción se deshabilita y no se registran tardanzas.</li>
        <li><strong>Rango de fechas:</strong> Fecha de inicio y fin para el seguimiento de asistencia.</li>
        <li><strong>Rango horario:</strong> Horas de inicio y fin de las reuniones diarias (utilizadas para crear sesiones de asistencia).</li>
        <li><strong>Respaldar grabaciones:</strong> Si está habilitado, se respaldarán automáticamente las grabaciones de las reuniones de Zoom.</li>
        <li><strong>Eliminar desde el origen:</strong> Si está habilitado, las grabaciones serán eliminadas de Zoom después de realizar el respaldo.</li>
    </ul>
';
$string['viewwarning'] = 'Asistencia ORT depende del plugin Attendance para persistir la información, ya que crea una sesión para guardar la asistencia. Si Attendance se desinstala del curso, se mostrará un mensaje de advertencia, ya que el plugin no funcionará correctamente.';
$string['errornoattendance'] = 'ADVERTENCIA: El plugin Attendance no está instalado y, sin él, Asistencia ORT no funcionará correctamente';

// Tasks
$string['schedulertask'] = 'Programador de Asistencia ORT';
$string['backuptask'] = 'Respaldo de grabaciones de Asistencia ORT';

// Errors
$string['error_daterange'] = 'La fecha de fin debe ser posterior a la fecha de inicio';
$string['error_timerange'] = 'La hora de fin debe ser posterior a la hora de inicio';

// Capabilities
$string['ortattendance:addinstance'] = 'Agregar una nueva actividad Asistencia ORT';
$string['ortattendance:view'] = 'Ver la actividad Asistencia ORT';

// Recollector
$string['recollectorsettings'] = 'Configuración del Recolector';
$string['recollectorsettings_desc'] = 'Configurar qué fuente de datos usar para la recolección de asistencia y gestión de grabaciones';

$string['recollectortype'] = 'Tipo de recolector';
$string['recollectortype_desc'] = 'Seleccione el tipo de recolector a utilizar:<br>
<strong>Recolector Zoom:</strong> Usa la API real de Zoom y la base de datos del plugin Zoom de Moodle';

$string['recollector_zoom'] = 'Recolector Zoom (Producción)';

$string['zoomconfig'] = 'Configuración de Zoom';
$string['zoomconfig_desc'] = 'Ajustes de configuración para la integración con Zoom';

$string['backupsettings'] = 'Ajustes de respaldo de grabaciones';
$string['backupsettings_desc'] = 'Configurar el respaldo automático de grabaciones de Zoom';

$string['localdirectory'] = 'Directorio local';
$string['localdirectory_desc'] = 'Ruta del directorio donde se almacenarán las grabaciones localmente';

$string['backuplimit'] = 'Límite de respaldo';
$string['backuplimit_desc'] = 'Número máximo de grabaciones a respaldar por ejecución programada';

$string['maxfilesize'] = 'Tamaño máximo de archivo';
$string['maxfilesize_desc'] = 'Tamaño máximo en MB para el respaldo de grabaciones';

$string['keeplocalafterupload'] = 'Mantener archivos locales después de subirlos a Moodle';
$string['keeplocalafterupload_desc'] = 'Mantener los archivos de grabaciones en el sistema de archivos local después de subirse correctamente a Moodle';
