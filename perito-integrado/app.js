(function () {
    "use strict";

    const form = document.getElementById("appraisalForm");
    const resultPanel = document.getElementById("resultPanel");
    const errorSummary = document.getElementById("formErrorSummary");
    const saveStatus = document.getElementById("saveStatus");
    const draftNotice = document.getElementById("draftNotice");
    const emailButton = document.getElementById("emailResult");
    const emailStatus = document.getElementById("emailStatus");
    const emailEndpoint = String(window.PERITO_CONFIG?.emailEndpoint || "").trim();
    const storageKey = "peritoVirtualDraftV1";
    let saveTimer = null;

    const formatCurrency = new Intl.NumberFormat("es-CO", {
        style: "currency",
        currency: "COP",
        maximumFractionDigits: 0
    });

    const cityRates = {
        bogota: 5900000,
        medellin: 5300000,
        cali: 3900000,
        barranquilla: 4100000,
        cartagena: 5100000,
        bucaramanga: 3600000,
        pereira: 3300000,
        manizales: 3200000,
        armenia: 3000000,
        ibague: 2900000
    };

    const normalize = (value) => String(value || "")
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .trim()
        .toLowerCase();

    function getFieldBlock(field) {
        return field.closest(".field-block");
    }

    function validateField(field) {
        const block = getFieldBlock(field);
        let valid = field.validity.valid;

        if (field.type === "file") {
            const files = Array.from(field.files || []);
            valid = files.length <= 8 && files.every((file) => /^image\/(jpeg|png|webp)$/.test(file.type));
        }

        if (block) {
            block.classList.toggle("invalid", !valid);
        }

        field.setAttribute("aria-invalid", String(!valid));
        return valid;
    }

    function validateForm() {
        const fields = Array.from(form.querySelectorAll("input, select"))
            .filter((field) => field.type !== "hidden" && field.type !== "checkbox");
        const invalid = fields.filter((field) => !validateField(field));
        errorSummary.hidden = invalid.length === 0;

        if (invalid.length) {
            invalid[0].focus({ preventScroll: true });
            getFieldBlock(invalid[0])?.scrollIntoView({ behavior: "smooth", block: "center" });
            errorSummary.focus({ preventScroll: true });
        }

        return invalid.length === 0;
    }

    function serializeForm() {
        const data = {};
        for (const field of form.elements) {
            if (!field.name || field.type === "file") continue;
            if (field.type === "checkbox") {
                if (field.name === "amenities") {
                    data.amenities = data.amenities || [];
                    if (field.checked) data.amenities.push(field.value);
                } else {
                    data[field.name] = field.checked;
                }
            } else {
                data[field.name] = field.value;
            }
        }
        return data;
    }

    function saveDraft() {
        try {
            localStorage.setItem(storageKey, JSON.stringify(serializeForm()));
            saveStatus.textContent = "Borrador guardado en este dispositivo.";
            window.clearTimeout(saveTimer);
            saveTimer = window.setTimeout(() => {
                saveStatus.textContent = "Los cambios se guardan en este dispositivo.";
            }, 1800);
        } catch (error) {
            saveStatus.textContent = "No fue posible guardar el borrador.";
        }
    }

    function restoreDraft() {
        let data;
        try {
            data = JSON.parse(localStorage.getItem(storageKey) || "null");
        } catch (error) {
            data = null;
        }
        if (!data) return;

        for (const field of form.elements) {
            if (!field.name || field.type === "file") continue;
            if (field.name === "amenities") {
                field.checked = Array.isArray(data.amenities) && data.amenities.includes(field.value);
            } else if (field.type === "checkbox") {
                field.checked = Boolean(data[field.name]);
            } else if (Object.prototype.hasOwnProperty.call(data, field.name)) {
                field.value = data[field.name];
            }
        }

        document.getElementById("locationPanel").hidden = !document.getElementById("mapToggle").checked;
        draftNotice.hidden = false;
    }

    function clearDraft() {
        try { localStorage.removeItem(storageKey); } catch (error) { /* El formulario sigue funcionando sin almacenamiento. */ }
        draftNotice.hidden = true;
    }

    function resetForm() {
        form.reset();
        form.querySelectorAll(".invalid").forEach((element) => element.classList.remove("invalid"));
        form.querySelectorAll("[aria-invalid]").forEach((element) => element.removeAttribute("aria-invalid"));
        document.getElementById("locationPanel").hidden = true;
        document.getElementById("mapPin").hidden = true;
        document.getElementById("fileSummary").textContent = "No hay fotografías seleccionadas.";
        errorSummary.hidden = true;
        resultPanel.hidden = true;
        clearDraft();
        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function baseRateForCity(city) {
        const key = normalize(city);
        const match = Object.keys(cityRates).find((name) => key.includes(name));
        return match ? cityRates[match] : 3200000;
    }

    function calculateEstimate(data) {
        const baseRate = baseRateForCity(data.city);
        const stratumFactors = { 1: .62, 2: .72, 3: .86, 4: 1, 5: 1.2, 6: 1.42 };
        const finishFactors = { basic: .86, standard: 1, superior: 1.13, luxury: 1.28 };
        const conditionFactors = { new: 1.08, excellent: 1.03, good: .96, renovation: .78 };
        const age = Number(data.age) || 0;
        const ageFactor = Math.max(.72, 1 - Math.max(0, age - 5) * .006);
        const privateArea = Number(data.privateArea) || 0;
        const freeArea = Number(data.freeArea) || 0;
        const parkingEquivalent = (Number(data.parking) || 0) * 6.5;
        const storageEquivalent = data.storage === "yes" ? 3.5 : 0;
        const weightedArea = privateArea + freeArea * .38 + parkingEquivalent + storageEquivalent;
        const amenityCount = Array.isArray(data.amenities) ? data.amenities.length : 0;
        const amenityFactor = 1 + Math.min(amenityCount, 6) * .008 + (data.gated ? .025 : 0);
        const propertyFactor = data.propertyType === "house" ? 1.04 : 1;
        const adjustedRate = baseRate * (stratumFactors[data.stratum] || 1) *
            (finishFactors[data.finishes] || 1) * (conditionFactors[data.condition] || 1) *
            ageFactor * amenityFactor * propertyFactor;
        const value = Math.round((weightedArea * adjustedRate) / 1000000) * 1000000;
        return {
            value,
            low: Math.round((value * .92) / 1000000) * 1000000,
            high: Math.round((value * 1.08) / 1000000) * 1000000,
            rate: Math.round(adjustedRate / 10000) * 10000,
            weightedArea
        };
    }

    function showResult() {
        const data = serializeForm();
        const estimate = calculateEstimate(data);
        document.getElementById("estimatedValue").textContent = formatCurrency.format(estimate.value);
        document.getElementById("estimatedRange").textContent = `Rango orientativo: ${formatCurrency.format(estimate.low)} – ${formatCurrency.format(estimate.high)}`;
        document.getElementById("squareMeterValue").textContent = formatCurrency.format(estimate.rate);
        document.getElementById("weightedArea").textContent = `${estimate.weightedArea.toLocaleString("es-CO", { maximumFractionDigits: 1 })} m²`;
        document.getElementById("resultAddress").textContent = `${data.address}, ${data.neighborhood}, ${data.city}`;
        resultPanel.hidden = false;
        emailStatus.textContent = "";
        emailStatus.className = "email-status";
        resultPanel.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    async function sendResultByEmail() {
        const emailField = document.getElementById("email");

        if (!validateField(emailField) || !emailField.value.trim()) {
            emailStatus.textContent = "Ingresa el correo que debe recibir el PDF.";
            emailStatus.className = "email-status error";
            emailField.focus();
            emailField.scrollIntoView({ behavior: "smooth", block: "center" });
            return;
        }

        if (!emailEndpoint) {
            emailStatus.textContent = "El plugin todavía no está conectado. Configura la URL del WordPress en config.js.";
            emailStatus.className = "email-status error";
            return;
        }

        emailButton.disabled = true;
        emailButton.textContent = "Enviando…";
        emailStatus.textContent = "Generando el PDF y entregándolo al servidor de correo…";
        emailStatus.className = "email-status";

        try {
            const payload = serializeForm();
            payload.website = "";

            const response = await fetch(emailEndpoint, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            });

            let result = {};
            try { result = await response.json(); } catch (error) { /* El estado HTTP conserva el diagnóstico. */ }

            if (!response.ok || !result.success) {
                throw new Error(result.message || "El servidor no pudo enviar el correo.");
            }

            emailStatus.textContent = result.message || `El PDF fue enviado a ${emailField.value.trim()}.`;
            emailStatus.className = "email-status success";
        } catch (error) {
            emailStatus.textContent = error.message || "No fue posible enviar el correo. Intenta nuevamente.";
            emailStatus.className = "email-status error";
        } finally {
            emailButton.disabled = false;
            emailButton.textContent = "Enviar PDF por correo";
        }
    }

    const mapToggle = document.getElementById("mapToggle");
    const locationPanel = document.getElementById("locationPanel");
    const mapCanvas = document.getElementById("mapCanvas");
    const mapPin = document.getElementById("mapPin");
    const latitude = document.getElementById("latitude");
    const longitude = document.getElementById("longitude");
    const locationStatus = document.getElementById("locationStatus");

    function setMapPoint(xRatio, yRatio, coordinates) {
        const x = Math.max(.03, Math.min(.97, xRatio));
        const y = Math.max(.08, Math.min(.92, yRatio));
        mapPin.style.left = `${x * 100}%`;
        mapPin.style.top = `${y * 100}%`;
        mapPin.hidden = false;
        if (coordinates) {
            latitude.value = coordinates.latitude.toFixed(6);
            longitude.value = coordinates.longitude.toFixed(6);
        } else {
            latitude.value = (4.5 + (1 - y) * .35).toFixed(6);
            longitude.value = (-74.2 + x * .25).toFixed(6);
        }
        locationStatus.textContent = "Punto seleccionado · pulsa Verificar Ubicación";
        locationStatus.classList.remove("verified");
        saveDraft();
    }

    mapToggle.addEventListener("change", () => {
        locationPanel.hidden = !mapToggle.checked;
        if (mapToggle.checked) locationPanel.scrollIntoView({ behavior: "smooth", block: "nearest" });
        saveDraft();
    });

    mapCanvas.addEventListener("click", (event) => {
        const rect = mapCanvas.getBoundingClientRect();
        setMapPoint((event.clientX - rect.left) / rect.width, (event.clientY - rect.top) / rect.height);
    });

    document.getElementById("useCurrentLocation").addEventListener("click", () => {
        if (!navigator.geolocation) {
            locationStatus.textContent = "Este navegador no permite consultar la ubicación.";
            return;
        }
        locationStatus.textContent = "Consultando ubicación…";
        navigator.geolocation.getCurrentPosition((position) => {
            setMapPoint(.5, .48, position.coords);
            locationStatus.textContent = "Ubicación del dispositivo obtenida · pulsa Verificar Ubicación";
        }, () => {
            locationStatus.textContent = "No se obtuvo permiso. Puedes marcar el punto manualmente.";
        }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 });
    });

    document.getElementById("verifyLocation").addEventListener("click", () => {
        const requiredAddress = ["city", "neighborhood", "address"].map((id) => document.getElementById(id));
        if (requiredAddress.some((field) => !field.value.trim())) {
            requiredAddress.forEach(validateField);
            locationStatus.textContent = "Completa primero ciudad, barrio y dirección.";
            return;
        }
        if (!latitude.value || !longitude.value) setMapPoint(.5, .48);
        locationStatus.textContent = `Ubicación verificada · ${latitude.value}, ${longitude.value}`;
        locationStatus.classList.add("verified");
        saveDraft();
    });

    const photos = document.getElementById("photos");
    photos.addEventListener("change", () => {
        const count = photos.files.length;
        document.getElementById("fileSummary").textContent = count ? `${count} fotografía${count === 1 ? "" : "s"} seleccionada${count === 1 ? "" : "s"}.` : "No hay fotografías seleccionadas.";
        validateField(photos);
    });

    form.addEventListener("input", (event) => {
        if (event.target.matches("input, select")) {
            if (event.target.getAttribute("aria-invalid") === "true") validateField(event.target);
            saveDraft();
        }
    });
    form.addEventListener("change", saveDraft);
    form.addEventListener("submit", (event) => {
        event.preventDefault();
        if (validateForm()) showResult();
    });

    document.getElementById("clearForm").addEventListener("click", resetForm);
    document.getElementById("clearDraftTop").addEventListener("click", resetForm);
    document.getElementById("editResult").addEventListener("click", () => form.scrollIntoView({ behavior: "smooth", block: "start" }));
    document.getElementById("printResult").addEventListener("click", () => window.print());
    emailButton.addEventListener("click", sendResultByEmail);

    restoreDraft();
})();
