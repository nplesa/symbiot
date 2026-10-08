package ro.npsoft.symbiot;

import ro.npsoft.symbiot.tracking.TrackingService;

import android.Manifest;
import android.content.Intent;
import android.content.pm.PackageManager;

import androidx.core.content.ContextCompat;

import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.JSObject;

@CapacitorPlugin(
    name = "Tracking",
    permissions = {
        @Permission(
            alias = "location",
            strings = {
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION
            }
        )
    }
)
public class TrackingPlugin extends Plugin {

    @Override
    public void load() {
        super.load();
    }

    @com.getcapacitor.PluginMethod
    public void start(PluginCall call) {

        if (ContextCompat.checkSelfPermission(
            getContext(),
            Manifest.permission.ACCESS_FINE_LOCATION
        ) != PackageManager.PERMISSION_GRANTED) {

            requestPermissionForAlias(
                "location",
                call,
                "locationPermissionCallback"
            );

            return;
        }

        startTrackingService();

        JSObject result = new JSObject();
        result.put("active", true);

        call.resolve(result);
    }

    @com.getcapacitor.PluginMethod
    public void stop(PluginCall call) {

        Intent intent =
            new Intent(getContext(), TrackingService.class);

        getContext().stopService(intent);

        JSObject result = new JSObject();
        result.put("active", false);

        call.resolve(result);
    }

    @com.getcapacitor.PluginMethod
    public void status(PluginCall call) {

        boolean granted =
            ContextCompat.checkSelfPermission(
                getContext(),
                Manifest.permission.ACCESS_FINE_LOCATION
            ) == PackageManager.PERMISSION_GRANTED;

        JSObject result = new JSObject();
        result.put("permissionGranted", granted);

        call.resolve(result);
    }

    private void startTrackingService() {

        Intent intent =
            new Intent(getContext(), TrackingService.class);

        ContextCompat.startForegroundService(
            getContext(),
            intent
        );
    }

    @SuppressWarnings("unused")
    private void locationPermissionCallback(
        PluginCall call
    ) {

        if (ContextCompat.checkSelfPermission(
            getContext(),
            Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED) {

            startTrackingService();

            JSObject result = new JSObject();
            result.put("active", true);

            call.resolve(result);

        } else {

            call.reject("Location permission denied");
        }
    }
}
